<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\DeviceSetting;
use App\Models\Telemetry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ScaleTare extends Command
{
    protected $signature = 'scale:tare {device=rack01}';

    protected $description =
        'Menghitung titik nol dari 20 telemetry sensor nyata yang baru';

    public function handle(): int
    {
        $device = Device::where('code', $this->argument('device'))->first();

        if (!$device) {
            $this->error('Perangkat tidak ditemukan.');
            return self::FAILURE;
        }

        $setting = $device->setting()->first();

        if (!$setting) {
            $this->error('Konfigurasi perangkat tidak ditemukan.');
            return self::FAILURE;
        }

        if ($setting->auto_enabled) {
            $this->error('Nonaktifkan otomatis sebelum melakukan tare.');
            return self::FAILURE;
        }

        $factor = $setting->calibration_factor;
        $stableRange = $setting->stable_range_g;

        if (
            !is_finite($factor) ||
            abs($factor) < 0.000001 ||
            $stableRange <= 0
        ) {
            $this->error('Konfigurasi kalibrasi atau kestabilan tidak valid.');
            return self::FAILURE;
        }

        $latest = Telemetry::where('device_id', $device->id)
            ->latest('id')
            ->first();

        if (
            !$latest ||
            $latest->received_at->lt(now()->subSeconds(2)) ||
            !$latest->sensor_ready ||
            $latest->raw_weight === null ||
            ($latest->payload['simulated'] ?? null) !== false
        ) {
            $this->error(
                'Belum ada telemetry sensor nyata yang siap dan baru.'
            );
            $this->line('Pastikan ESP32 dan mqtt:listen berjalan.');
            return self::FAILURE;
        }

        if ($latest->pump_on) {
            $this->error('Pompa harus OFF sebelum tare.');
            return self::FAILURE;
        }

        $this->warn('Pastikan tatakan KOSONG, diam, dan suplai pompa dilepas.');

        if (!$this->confirm('Mulai mengambil 20 pembacaan baru?', true)) {
            return self::SUCCESS;
        }

        // Mulai dari pesan terakhir setelah konfirmasi.
        // Pesan lama tidak ikut menjadi sampel tare.
        $baseline = Telemetry::where('device_id', $device->id)
            ->latest('id')
            ->first();

        if (
            !$baseline ||
            $baseline->received_at->lt(now()->subSeconds(2)) ||
            !$baseline->sensor_ready ||
            $baseline->pump_on ||
            ($baseline->payload['simulated'] ?? null) !== false
        ) {
            $this->error('Kondisi perangkat berubah. Tare dibatalkan.');
            return self::FAILURE;
        }

        $lastId = $baseline->id;
        $bootId = $baseline->boot_id;
        $lastSequence = $baseline->sequence;
        $lastUptime = $baseline->uptime_ms;

        $samples = [];
        $deadline = microtime(true) + 20;
        $lastAcceptedAt = microtime(true);

        try {
            while (count($samples) < 20) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException(
                        'Waktu habis sebelum 20 sampel terkumpul.'
                    );
                }

                $messages = Telemetry::where('device_id', $device->id)
                    ->where('id', '>', $lastId)
                    ->orderBy('id')
                    ->limit(50)
                    ->get();

                foreach ($messages as $message) {
                    $lastId = $message->id;

                    // Pesan simulasi tidak boleh menjadi sampel.
                    if (($message->payload['simulated'] ?? null) !== false) {
                        continue;
                    }

                    if ($message->boot_id !== $bootId) {
                        throw new RuntimeException(
                            'ESP32 restart saat tare. Ulangi tare.'
                        );
                    }

                    if (
                        !$message->sensor_ready ||
                        $message->raw_weight === null
                    ) {
                        throw new RuntimeException(
                            'Sensor tidak siap saat tare.'
                        );
                    }

                    if ($message->pump_on) {
                        throw new RuntimeException(
                            'Pompa dilaporkan ON. Tare dibatalkan.'
                        );
                    }

                    if ($message->received_at->lt(now()->subSeconds(2))) {
                        throw new RuntimeException(
                            'Pembacaan terlambat atau tidak lagi baru.'
                        );
                    }

                    if (
                        $message->sequence <= $lastSequence ||
                        $message->uptime_ms <= $lastUptime
                    ) {
                        throw new RuntimeException(
                            'Urutan telemetry tidak valid.'
                        );
                    }

                    // Jeda besar menandakan sampel tidak berkelanjutan.
                    if ($message->uptime_ms - $lastUptime > 2000) {
                        throw new RuntimeException(
                            'Jeda pembacaan terlalu lama. Ulangi tare.'
                        );
                    }

                    $lastSequence = $message->sequence;
                    $lastUptime = $message->uptime_ms;
                    $lastAcceptedAt = microtime(true);

                    $samples[] = $message->raw_weight;

                    $this->line(
                        'Sampel ' . count($samples) . '/20: ' .
                        $message->raw_weight
                    );

                    if (count($samples) >= 20) {
                        break;
                    }
                }

                if (microtime(true) - $lastAcceptedAt > 2) {
                    throw new RuntimeException(
                        'Telemetry sensor nyata terputus lebih dari 2 detik.'
                    );
                }

                if (count($samples) < 20) {
                    usleep(100000);
                }
            }

            $rangeGrams =
                (max($samples) - min($samples)) / abs($factor);

            if ($rangeGrams > $stableRange) {
                throw new RuntimeException(
                    'Tatakan belum stabil. Rentang pembacaan: ' .
                    number_format($rangeGrams, 3) . ' gram.'
                );
            }

            $offset = array_sum($samples) / count($samples);

            DB::transaction(function () use (
                $device,
                $factor,
                $stableRange,
                $bootId,
                $offset
            ) {
                // Urutan penguncian sama dengan TelemetryService.
                Device::whereKey($device->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $current = DeviceSetting::where('device_id', $device->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $current->auto_enabled ||
                    $current->calibration_factor !== $factor ||
                    $current->stable_range_g !== $stableRange
                ) {
                    throw new RuntimeException(
                        'Konfigurasi berubah saat tare. Ulangi proses.'
                    );
                }

                $last = Telemetry::where('device_id', $device->id)
                    ->latest('id')
                    ->firstOrFail();

                if (
                    $last->boot_id !== $bootId ||
                    !$last->sensor_ready ||
                    $last->pump_on ||
                    $last->received_at->lt(now()->subSeconds(2)) ||
                    ($last->payload['simulated'] ?? null) !== false
                ) {
                    throw new RuntimeException(
                        'Kondisi perangkat berubah sebelum tare disimpan.'
                    );
                }

                $current->update([
                    'tare_offset' => $offset,
                    'tared_at' => now(),
                ]);
            });

            $this->info('TARE BERHASIL.');
            $this->line('Jumlah sampel: ' . count($samples));
            $this->line('Tare offset: ' . number_format($offset, 3, '.', ''));
            $this->line(
                'Rentang sampel: ' .
                number_format($rangeGrams, 3) . ' gram'
            );
            $this->line(
                'Telemetry berikutnya akan dihitung menjadi gram.'
            );

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Tare gagal: ' . $e->getMessage());
            $this->line('Hasil tare sebelumnya tidak ditimpa.');

            return self::FAILURE;
        }
    }
}