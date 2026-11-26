<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Telemetry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TelemetryService
{
    public function handle(array $payload): Telemetry
    {
        $data = Validator::make($payload, [
            'device_id' => ['required', 'string', 'max:50'],
            'boot_id' => ['required', 'uuid'],
            'sequence' => ['required', 'integer', 'min:0'],
            'uptime_ms' => ['required', 'integer', 'min:0'],
            'sensor_ready' => ['required', 'boolean'],
            'raw_weight' => [
                'present',
                'nullable',
                'integer',
                'between:-8388608,8388607',
            ],
            'pump_on' => ['required', 'boolean'],
        ])->validate();

        // Pesan MQTT menggunakan boolean JSON asli.
        foreach (['sensor_ready', 'pump_on'] as $field) {
            if (!is_bool($payload[$field])) {
                throw ValidationException::withMessages([
                    $field => 'Gunakan boolean JSON true atau false.',
                ]);
            }
        }

        if ($data['sensor_ready'] && $data['raw_weight'] === null) {
            throw ValidationException::withMessages([
                'raw_weight' => 'Nilai sensor wajib tersedia saat sensor_ready true.',
            ]);
        }

        if (!$data['sensor_ready'] && $data['raw_weight'] !== null) {
            throw ValidationException::withMessages([
                'raw_weight' => 'Gunakan null saat sensor tidak siap.',
            ]);
        }

        return DB::transaction(function () use ($data, $payload) {
            // Penguncian perangkat menserialkan penyimpanan
            // pesan untuk perangkat yang sama.
            $device = Device::query()
                ->where('code', $data['device_id'])
                ->lockForUpdate()
                ->first();

            if (!$device) {
                throw ValidationException::withMessages([
                    'device_id' => 'Perangkat belum terdaftar.',
                ]);
            }

            $existing = Telemetry::query()
                ->where('device_id', $device->id)
                ->where('boot_id', $data['boot_id'])
                ->where('sequence', $data['sequence'])
                ->first();

            // Pengiriman ulang tidak membuat data baru.
            if ($existing) {
                return $existing;
            }

            $setting = $device->setting()
                ->with('bottle')
                ->first();

            if (!$setting || !$setting->bottle) {
                throw ValidationException::withMessages([
                    'device_id' => 'Konfigurasi perangkat atau botol belum tersedia.',
                ]);
            }

            $factor = $setting->calibration_factor;

            if (!is_finite($factor) || abs($factor) < 0.000001) {
                throw ValidationException::withMessages([
                    'calibration_factor' => 'Faktor kalibrasi tidak valid.',
                ]);
            }

            $totalWeight = null;
            $waterWeight = null;
            $waterPercent = null;

            $presentThreshold = (float) ($setting->bottle_present_g ?? 14.0);
            $removedThreshold = (float) ($setting->bottle_removed_g ?? 9.0);

            // Hitung hanya jika sensor siap dan server sudah tare.
            if (
                $data['sensor_ready'] &&
                $setting->tare_offset !== null &&
                $setting->tared_at !== null
            ) {
                $totalWeight =
                    ($data['raw_weight'] - $setting->tare_offset)
                    / $factor;

                // Gunakan spesifikasi botol dari sesi aktif jika ada
                $activeSession = \App\Models\UsageSession::where('device_id', $device->id)
                    ->where('status', 'active')
                    ->first();

                $emptyWeight = $activeSession && $activeSession->bottle_empty_weight_g > 0
                    ? (float) $activeSession->bottle_empty_weight_g
                    : (float) $setting->bottle->empty_weight_g;

                $capacity = $activeSession && $activeSession->water_capacity_g > 0
                    ? (float) $activeSession->water_capacity_g
                    : (float) $setting->bottle->water_capacity_g;

                if ($capacity <= 0) {
                    $capacity = 700.0;
                }

                // Filter Glitch Penurunan Sesaat & Fluktuasi saat Pompa Aktif:
                $prevTelemetry = Telemetry::where('device_id', $device->id)->latest('id')->first();
                if ($data['pump_on'] && $prevTelemetry && $prevTelemetry->total_weight_g !== null) {
                    $diffDrop = (float) $prevTelemetry->total_weight_g - (float) $totalWeight;
                    // Jika terjadi drop mendadak > 20g akibat getaran motor pompa
                    if ($diffDrop > 20.0 && $totalWeight > ($removedThreshold + 5.0)) {
                        $totalWeight = (float) $prevTelemetry->total_weight_g;
                    }
                    // Cegah fluktuasi riak air kecil turun 1%-2% saat pompa sedang mengisi (monotonik)
                    elseif ($diffDrop > 0 && $diffDrop < 15.0 && $totalWeight > ($removedThreshold + 5.0)) {
                        $totalWeight = (float) $prevTelemetry->total_weight_g;
                    }
                }

                // Nilai selisih berat air di dalam botol
                $waterWeight = $totalWeight - $emptyWeight;

                $waterPercent = max(
                    0,
                    min(100, $waterWeight / $capacity * 100)
                );
            }

            $bottlePresent = false;
            if ($totalWeight !== null) {
                if ($totalWeight >= $presentThreshold) {
                    $bottlePresent = true;
                } elseif ($totalWeight <= $removedThreshold) {
                    $bottlePresent = false;
                } else {
                    $prev = Telemetry::where('device_id', $device->id)->latest('id')->first();
                    $bottlePresent = $prev ? (bool) $prev->bottle_present : false;
                }
            } else {
                // Jangan langsung simpulkan botol diangkat jika 1 telemetry kehilangan sampel ADC sesaat (sensor_ready=false)
                $prev = Telemetry::where('device_id', $device->id)->latest('id')->first();
                $bottlePresent = $prev ? (bool) $prev->bottle_present : false;
            }

            $receivedAt = now();

            $telemetry = Telemetry::create([
                'device_id' => $device->id,
                'boot_id' => $data['boot_id'],
                'sequence' => $data['sequence'],
                'uptime_ms' => $data['uptime_ms'],
                'sensor_ready' => $data['sensor_ready'],
                'raw_weight' => $data['raw_weight'],
                'pump_on' => $data['pump_on'],

                'total_weight_g' => $totalWeight,
                'water_weight_g' => $waterWeight,
                'water_percent' => $waterPercent,
                'bottle_present' => $bottlePresent,

                'calibration_factor' => $factor,
                'tare_offset' => $setting->tare_offset,
                'payload' => $payload,
                'received_at' => $receivedAt,
            ]);

            $device->update([
                'last_seen_at' => $receivedAt,
            ]);

            // Evaluasi aturan bisnis & logika kendali refill otomatis di server
            try {
                app(RefillControlService::class)->evaluate($telemetry, $device);
            } catch (\Throwable $e) {
                // Jangan gagalkan penyimpanan telemetry jika terjadi anomali pada evaluasi
                \Illuminate\Support\Facades\Log::error('Gagal evaluasi kontrol refill: ' . $e->getMessage());
            }

            return $telemetry;
        });
    }
}