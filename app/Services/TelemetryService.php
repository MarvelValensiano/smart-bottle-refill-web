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

            // Hitung hanya jika sensor siap dan server sudah tare.
            if (
                $data['sensor_ready'] &&
                $setting->tare_offset !== null &&
                $setting->tared_at !== null
            ) {
                $totalWeight =
                    ($data['raw_weight'] - $setting->tare_offset)
                    / $factor;

                // Nilai negatif tetap disimpan untuk diagnosis.
                $waterWeight =
                    $totalWeight - $setting->bottle->empty_weight_g;

                $capacity = $setting->bottle->water_capacity_g;

                if ($capacity <= 0) {
                    throw ValidationException::withMessages([
                        'water_capacity_g' => 'Kapasitas air harus lebih besar dari nol.',
                    ]);
                }

                $waterPercent = max(
                    0,
                    min(100, $waterWeight / $capacity * 100)
                );
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

                // Deteksi botol dengan histeresis dibuat
                // pada layanan kontrol di tahap berikutnya.
                'bottle_present' => null,

                'calibration_factor' => $factor,
                'tare_offset' => $setting->tare_offset,
                'payload' => $payload,
                'received_at' => $receivedAt,
            ]);

            $device->update([
                'last_seen_at' => $receivedAt,
            ]);

            return $telemetry;
        });
    }
}