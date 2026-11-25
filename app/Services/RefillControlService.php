<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceEvent;
use App\Models\Refill;
use App\Models\Telemetry;
use App\Models\UsageSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class RefillControlService
{
    /**
     * Evaluasi telemetry dan tentukan keputusan kontrol otomatis.
     * Mengembalikan daftar command yang harus dikirim ke perangkat jika ada.
     */
    public function evaluate(Telemetry $telemetry, Device $device): array
    {
        $setting = $device->setting()->with('bottle')->first();

        if (!$setting) {
            return [];
        }

        $commandsToSend = [];
        $totalWeight = $telemetry->total_weight_g;
        $waterPercent = $telemetry->water_percent;

        // 1. Deteksi Keberadaan Botol dengan Histeresis
        $bottlePresent = $this->detectBottlePresence($telemetry, $setting, $device);
        $telemetry->update(['bottle_present' => $bottlePresent]);

        // 2. Ambil Sesi Penggunaan Aktif untuk Slot ini
        $activeSession = UsageSession::where('device_id', $device->id)
            ->where('status', 'active')
            ->first();

        // Pemulihan otomatis gangguan: Jika sebelumnya botol diangkat dan sekarang sudah diletakkan kembali stabil
        if ($activeSession && $activeSession->is_locked && $activeSession->lock_reason === 'BOTOL_DIANGKAT' && $bottlePresent) {
            $activeSession->update([
                'is_locked' => false,
                'lock_reason' => null,
            ]);
            $this->createEvent($device, null, 'bottle_returned', 'info', 'Botol diletakkan kembali di tatakan timbangan. Sistem siap beroperasi.');
        }

        // 3. Ambil Siklus Refill yang Sedang Berjalan atau Dijeda (jika ada)
        $activeRefill = Refill::where('device_id', $device->id)
            ->whereIn('status', ['filling', 'paused'])
            ->latest('id')
            ->first();

        // -------------------------------------------------------------
        // KASUS A: SEDANG ADA SIKLUS REFILL AKTIF (FILLING / PAUSED)
        // -------------------------------------------------------------
        if ($activeRefill) {
            $stopPercent = (float) ($setting->stop_percent ?? 80.0);
            $targetWeight = (float) $activeRefill->target_total_weight_g;

            // A1. PENGAMANAN: BOTOL SEDANG DIANGKAT
            if (!$bottlePresent) {
                if ($activeRefill->status === 'filling') {
                    // Jeda proses refill sementara, JANGAN batalkan permanen! Matikan pompa agar tidak tumpah.
                    $activeRefill->update([
                        'status' => 'paused',
                        'end_total_weight_g' => $totalWeight,
                    ]);

                    $this->createEvent($device, $activeRefill, 'bottle_lifted_paused', 'warning', 'Botol diangkat saat pengisian. Pompa dimatikan dan status refill dijeda.');
                    $commandsToSend[] = $this->createCommand($device, $activeRefill, 'set_pump', ['pump' => false, 'reason' => 'paused']);
                } elseif ($activeRefill->status === 'paused') {
                    // Jika botol dibiarkan terangkat lebih dari 2 menit (120 detik), batalkan agar siklus tidak menggantung
                    if ($activeRefill->updated_at && $activeRefill->updated_at->diffInSeconds(now()) >= 120) {
                        $activeRefill->update([
                            'status' => 'cancelled',
                            'finish_reason' => 'PAUSE_TIMEOUT_ABANDONED',
                            'finished_at' => now(),
                            'end_total_weight_g' => $totalWeight,
                        ]);
                        $this->createEvent($device, $activeRefill, 'refill_timeout', 'info', 'Botol tidak diletakkan kembali selama 2 menit. Siklus refill ditutup.');
                    }
                }

                return $commandsToSend;
            }

            // A2. KONDISI BOTOL ADA DI TATAKAN
            // Ambil telemetry sebelumnya untuk verifikasi kestabilan & proteksi anti-lonjakan (anti-spike)
            $prevTelemetry = Telemetry::where('device_id', $device->id)
                ->where('id', '<', $telemetry->id)
                ->latest('id')
                ->first();

            $isSpike = false;
            if ($totalWeight !== null) {
                // Lonjakan ekstrem melebihi batas fisik tatakan/botol (misal > 1200g atau > target + 120g)
                if ($totalWeight > 1200.0 || ($targetWeight > 0 && $totalWeight > ($targetWeight + 150.0))) {
                    $isSpike = true;
                }
                // Lonjakan instan lebih dari 35 gram dalam 500 ms (motor pompa rata-rata mengisi 10-25 g/s)
                if ($prevTelemetry && $prevTelemetry->total_weight_g !== null) {
                    if (($totalWeight - (float) $prevTelemetry->total_weight_g) > 35.0) {
                        $isSpike = true;
                    }
                }
            }

            // Target berhenti tercapai jika memenuhi nilai ambang DAN bukan lonjakan palsu (spike)
            $rawTargetCondition = ($waterPercent !== null && $waterPercent >= $stopPercent) ||
                                  ($totalWeight !== null && $targetWeight > 0 && $totalWeight >= $targetWeight);

            $isTargetReached = false;
            if ($rawTargetCondition && !$isSpike) {
                // Pastikan bukan lompatan mendadak dari persentase rendah (misal dari 30% tiba-tiba loncat)
                $wasCloseOrConfirmed = false;
                if ($prevTelemetry) {
                    $prevPct = (float) ($prevTelemetry->water_percent ?? 0);
                    $prevW = (float) ($prevTelemetry->total_weight_g ?? 0);
                    // Pembacaan sebelumnya sudah dekat target (selisih <= 6% atau <= 35g)
                    if ($prevPct >= ($stopPercent - 6.0) || ($targetWeight > 0 && $prevW >= ($targetWeight - 35.0))) {
                        $wasCloseOrConfirmed = true;
                    }
                }
                if ($wasCloseOrConfirmed || !$prevTelemetry) {
                    $isTargetReached = true;
                }
            }

            if ($isTargetReached) {
                $endWeight = (float) $totalWeight;
                $addedWater = max(0, $endWeight - (float) $activeRefill->start_total_weight_g);

                $activeRefill->update([
                    'status' => 'completed',
                    'end_total_weight_g' => $endWeight,
                    'added_water_g' => $addedWater,
                    'finished_at' => now(),
                    'pump_off_confirmed_at' => now(),
                    'finish_reason' => 'TARGET_REACHED',
                    'is_success_counted' => true,
                ]);

                $this->createEvent($device, $activeRefill, 'refill_completed', 'info', 'Pengisian air selesai dan berhasil mencapai target.');
                $commandsToSend[] = $this->createCommand($device, $activeRefill, 'set_pump', ['pump' => false, 'reason' => 'completed']);
                return $commandsToSend;
            }

            // A3. TARGET BELUM TERCAPAI:
            // Jika sebelumnya statusnya 'paused':
            if ($activeRefill->status === 'paused') {
                // Kasus A3a: Dijeda karena masalah sumber air / berat tidak bertambah
                if ($activeRefill->finish_reason === 'SUMBER_AIR_KOSONG') {
                    $refTime = $telemetry->received_at ?? now();
                    $pauseStart = $activeRefill->updated_at ?? $activeRefill->created_at;
                    $pauseElapsed = $pauseStart ? abs($refTime->diffInSeconds($pauseStart)) : 0;

                    // Beri jeda 10 detik dengan pompa mati dan alarm buzzer/LED aktif
                    if ($pauseElapsed < 10) {
                        return $commandsToSend;
                    }

                    // Setelah 10 detik berlalu: Pompa mengecek kembali apakah airnya sudah ada atau belum
                    $activeRefill->update([
                        'status' => 'filling',
                        'finish_reason' => null,
                        'started_at' => now(), // Reset timer agar jendela evaluasi 10 detik dihitung dari saat ini
                    ]);

                    $this->createEvent($device, $activeRefill, 'refill_rechecking_water', 'info', 'Jeda 10 detik peringatan selesai. Menyalakan pompa kembali untuk mengecek ketersediaan air.');
                    $commandsToSend[] = $this->createCommand($device, $activeRefill, 'set_buzzer', ['buzzer' => false]);
                    $commandsToSend[] = $this->createCommand($device, $activeRefill, 'set_pump', ['pump' => true, 'lease_ms' => 30000]);
                    return $commandsToSend;
                }

                // Kasus A3b: Dijeda karena botol diangkat lalu diletakkan kembali
                // Beri jeda sebentar agar pembacaan timbangan stabil sebelum menyalakan pompa lagi
                if (!$this->isBottlePlacementStable($device, $telemetry, $setting)) {
                    return $commandsToSend;
                }

                // Botol sudah stabil & belum sampai target -> lanjutkan pengisian otomatis!
                $activeRefill->update([
                    'status' => 'filling',
                    'finish_reason' => null,
                    'started_at' => now(),
                ]);

                $this->createEvent($device, $activeRefill, 'refill_resumed', 'info', 'Botol diletakkan kembali. Melanjutkan pengisian air hingga target ' . $stopPercent . '%.');
                $commandsToSend[] = $this->createCommand($device, $activeRefill, 'set_pump', ['pump' => true, 'lease_ms' => 30000]);
                return $commandsToSend;
            }

            // A4. DETEKSI GANGGUAN SUMBER AIR (BERAT TIDAK BERTAMBAH SELAMA 10 DETIK)
            $refTime = $telemetry->received_at ?? now();
            $fillStart = $activeRefill->started_at ?? $activeRefill->created_at;
            $fillDuration = $fillStart ? abs($refTime->diffInSeconds($fillStart)) : 0;

            // Evaluasi dilakukan setelah pompa berjalan sekurangnya 10 detik
            // Dan hanya jika pembacaan saat ini bukan lonjakan / drop sesaat (glitch)
            $isGlitchDrop = false;
            if ($prevTelemetry && $prevTelemetry->total_weight_g !== null && $totalWeight !== null) {
                if (((float) $prevTelemetry->total_weight_g - (float) $totalWeight) > 25.0) {
                    $isGlitchDrop = true; // Penurunan mendadak > 25g dalam 500ms adalah noise getaran pompa, bukan air habis
                }
            }

            if ($fillDuration >= 10 && !$isGlitchDrop && !$isSpike) {
                // Ambil 3 sampel telemetry terkini untuk meratakan noise getaran pompa
                $recentWeights = Telemetry::where('device_id', $device->id)
                    ->where('id', '<=', $telemetry->id)
                    ->where('id', '>=', $telemetry->id - 5)
                    ->latest('id')
                    ->take(3)
                    ->pluck('total_weight_g')
                    ->filter(fn($w) => $w !== null && $w > 10.0 && $w < 1500.0)
                    ->values();

                // Ambil 3 sampel telemetry dari jendela 8-14 detik yang lalu
                $oldWeights = Telemetry::where('device_id', $device->id)
                    ->where('id', '<=', $telemetry->id)
                    ->where('received_at', '<=', $refTime->copy()->subSeconds(7))
                    ->where('received_at', '>=', $refTime->copy()->subSeconds(14))
                    ->latest('id')
                    ->take(3)
                    ->pluck('total_weight_g')
                    ->filter(fn($w) => $w !== null && $w > 10.0 && $w < 1500.0)
                    ->values();

                if ($recentWeights->count() >= 2 && $oldWeights->count() >= 1) {
                    $recentAvg = $recentWeights->avg();
                    $oldAvg = $oldWeights->avg();
                    $gain = (float) $recentAvg - (float) $oldAvg;
                    $minGain = (float) ($setting->min_gain_g ?? 2.0);

                    // Air macet/habis: kenaikan berat rata-rata kurang dari ambang minimum (misal < 2g dalam 10s)
                    // Dan kenaikan tidak negatif ekstrem (jika negatif ekstrem biasanya botol diangkat atau noise)
                    if ($gain < $minGain && $gain > -15.0) {
                        // Berat botol terbukti stabil tidak bertambah selama 10 detik pengisian!
                        $activeRefill->update([
                            'status' => 'paused',
                            'finish_reason' => 'SUMBER_AIR_KOSONG',
                            'retry_count' => $activeRefill->retry_count + 1,
                            'end_total_weight_g' => $totalWeight,
                        ]);

                        $this->createEvent($device, $activeRefill, 'water_flow_error', 'warning', 'Berat botol tidak bertambah selama 10 detik saat refill (sumber air habis/aliran macet). Pompa dimatikan, buzzer & LED merah alarm aktif 10 detik.');

                        // 1. Pompa berhenti
                        $commandsToSend[] = $this->createCommand($device, $activeRefill, 'set_pump', ['pump' => false, 'reason' => 'water_empty']);

                        // 2. Buzzer menyala terus selama 10 detik
                        $commandsToSend[] = $this->createCommand($device, $activeRefill, 'set_buzzer', [
                            'buzzer' => true,
                            'duration_ms' => 10000,
                            'frequency' => 1500,
                        ]);

                        return $commandsToSend;
                    }
                }
            }

            // A5. JIKA STATUSNYA SEDANG MENGISI ('filling'):
            // Pengamanan: Batas Waktu Maksimum Refill Aktif (T_max = 60 detik)
            $maxDurationSec = 60;
            if ($activeRefill->started_at && abs(now()->diffInSeconds($activeRefill->started_at)) >= $maxDurationSec) {
                $activeRefill->update([
                    'status' => 'failed',
                    'finish_reason' => 'TIMEOUT_T_MAX',
                    'finished_at' => now(),
                    'end_total_weight_g' => $totalWeight,
                ]);

                $this->createEvent($device, $activeRefill, 'refill_timeout', 'error', 'Durasi pengisian melampaui batas waktu maksimum T_max.');
                $commandsToSend[] = $this->createCommand($device, $activeRefill, 'set_pump', ['pump' => false]);
                return $commandsToSend;
            }

            // Pompa tetap berjalan jika kondisi aman
            return $commandsToSend;
        }

        // -------------------------------------------------------------
        // KASUS B: TIDAK ADA REFILL AKTIF -> CEK KELAYAKAN MULAI REFILL
        // -------------------------------------------------------------
        $startPercent = (float) ($setting->start_percent ?? 30.0);
        $stopPercent = (float) ($setting->stop_percent ?? 80.0);

        if (
            $bottlePresent &&
            $telemetry->sensor_ready &&
            $waterPercent !== null &&
            $waterPercent <= $startPercent
        ) {
            // Pastikan posisi botol sudah tenang & pembacaan stabil selama jeda waktu sebelum pompa dinyalakan
            if (!$this->isBottlePlacementStable($device, $telemetry, $setting)) {
                return $commandsToSend;
            }

            $emptyWeight = (float) ($activeSession?->bottle_empty_weight_g ?? $setting->bottle?->empty_weight_g ?? 19.0);
            $capacity = (float) ($activeSession?->water_capacity_g ?? $setting->bottle?->water_capacity_g ?? 700.0);
            $targetTotalWeight = $emptyWeight + ($capacity * ($stopPercent / 100.0));

            $newRefill = Refill::create([
                'refill_id' => (string) Str::uuid(),
                'device_id' => $device->id,
                'bottle_id' => $activeSession?->bottle_id ?? $setting->bottle_id ?? 1,
                'athlete_id' => $activeSession?->athlete_id ?? 1,
                'session_id' => $activeSession?->id,
                'status' => 'filling',
                'bottle_empty_weight_g' => $emptyWeight,
                'water_capacity_g' => $capacity,
                'target_percent' => $stopPercent,
                'target_total_weight_g' => $targetTotalWeight,
                'start_total_weight_g' => (float) $totalWeight,
                'started_at' => now(),
            ]);

            $this->createEvent($device, $newRefill, 'refill_started', 'info', 'Air botol <= ' . $startPercent . '%. Memulai pengisian otomatis.');
            $commandsToSend[] = $this->createCommand($device, $newRefill, 'set_pump', ['pump' => true, 'lease_ms' => 30000]);

            return $commandsToSend;
        }

        return $commandsToSend;
    }

    /**
     * Hitung status tampilan terkini sesuai aturan laporan Bab 2.3 & 6.2
     */
    public function getStatusLabel(?Telemetry $latest, Device $device, ?UsageSession $session): array
    {
        if (!$latest || $latest->received_at->lt(now()->subSeconds(10))) {
            return [
                'status' => 'OFFLINE',
                'color' => 'gray',
                'badge' => 'OFFLINE',
                'description' => 'Perangkat tidak mengirim data / offline.',
            ];
        }

        if (!$latest->sensor_ready || $latest->raw_weight === null) {
            return [
                'status' => 'MEMBACA',
                'color' => 'yellow',
                'badge' => 'MEMBACA',
                'description' => 'Sensor timbangan sedang stabilisasi / membaca.',
            ];
        }

        if ($session && $session->is_locked) {
            return [
                'status' => 'TERKUNCI_GANGGUAN',
                'color' => 'red',
                'badge' => 'TERKUNCI GANGGUAN',
                'description' => $session->lock_reason === 'SUMBER_TIDAK_TERDETEKSI'
                    ? 'Aliran air tidak terdeteksi - periksa galon, selang, atau pompa.'
                    : 'Pengisian terkunci karena gangguan (' . $session->lock_reason . ').',
            ];
        }

        // Cek apakah ada proses refill (sedang filling atau paused)
        $activeRefill = Refill::where('device_id', $device->id)
            ->whereIn('status', ['filling', 'paused'])
            ->latest('id')
            ->first();

        // Cek apakah botol sedang diangkat
        $setting = $device->setting;
        $bottleRemovedG = $setting ? (float) $setting->bottle_removed_g : 9.0;
        $isBottleRemoved = $latest->total_weight_g !== null && $latest->total_weight_g < $bottleRemovedG;

        if ($activeRefill && $activeRefill->finish_reason === 'SUMBER_AIR_KOSONG') {
            return [
                'status' => 'SUMBER_KOSONG',
                'color' => 'red',
                'badge' => 'SUMBER AIR KOSONG',
                'description' => 'Berat botol tidak bertambah selama 10 detik saat refill. Memeriksa kembali ketersediaan air...',
            ];
        }

        if ($activeRefill && ($activeRefill->status === 'paused' || $isBottleRemoved)) {
            return [
                'status' => 'REFILL_DIJEDA',
                'color' => 'amber',
                'badge' => 'REFILL DIJEDA',
                'description' => 'Pengisian dijeda sementara karena botol diangkat. Letakkan botol kembali untuk melanjutkan pengisian.',
            ];
        }

        if ($isBottleRemoved) {
            return [
                'status' => 'DIANGKAT',
                'color' => 'orange',
                'badge' => 'DIANGKAT',
                'description' => 'Botol tidak berada di atas tatakan timbangan.',
            ];
        }

        if ($activeRefill && $activeRefill->status === 'filling') {
            return [
                'status' => 'SEDANG_REFILL',
                'color' => 'blue',
                'badge' => 'SEDANG REFILL',
                'description' => 'Pompa aktif mengalirkan air ke botol.',
            ];
        }

        $percent = $latest->water_percent;
        if ($percent === null) {
            return [
                'status' => 'MEMBACA',
                'color' => 'yellow',
                'badge' => 'MEMBACA',
                'description' => 'Data kalibrasi belum lengkap.',
            ];
        }

        if ($percent <= 30.0) {
            return [
                'status' => 'PERLU_REFILL',
                'color' => 'rose',
                'badge' => 'PERLU REFILL',
                'description' => 'Sisa air rendah (<= 30%). Membutuhkan pengisian ulang.',
            ];
        }

        if ($percent < 50.0) {
            return [
                'status' => 'SEDIKIT',
                'color' => 'amber',
                'badge' => 'SEDIKIT',
                'description' => 'Sisa air botol dalam kondisi sedikit.',
            ];
        }

        if ($percent < 80.0) {
            return [
                'status' => 'SEDANG',
                'color' => 'cyan',
                'badge' => 'SEDANG',
                'description' => 'Sisa air botol cukup / sedang.',
            ];
        }

        return [
            'status' => 'PENUH',
            'color' => 'emerald',
            'badge' => 'PENUH',
            'description' => 'Botol terisi penuh (80-100%).',
        ];
    }

    private function detectBottlePresence(Telemetry $telemetry, $setting, Device $device): bool
    {
        $weight = $telemetry->total_weight_g;
        if ($weight === null) {
            return false;
        }

        $presentThreshold = (float) ($setting->bottle_present_g ?? 14.0);
        $removedThreshold = (float) ($setting->bottle_removed_g ?? 9.0);

        if ($weight >= $presentThreshold) {
            return true;
        }

        if ($weight <= $removedThreshold) {
            return false;
        }

        // Jika di antara threshold, gunakan status pembacaan sebelumnya
        $previous = Telemetry::where('device_id', $device->id)
            ->where('id', '<', $telemetry->id)
            ->latest('id')
            ->first();

        return $previous ? (bool) $previous->bottle_present : false;
    }

    private function createCommand(Device $device, ?Refill $refill, string $type, array $payload): DeviceCommand
    {
        $seq = DeviceCommand::where('device_id', $device->id)->max('command_sequence') ?? 0;

        return DeviceCommand::create([
            'command_id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'refill_id' => $refill?->id,
            'target_boot_id' => (string) Str::uuid(),
            'command_sequence' => $seq + 1,
            'type' => $type,
            'payload' => $payload,
            'status' => 'pending',
            'lease_ms' => $payload['lease_ms'] ?? 30000,
            'expires_at' => now()->addSeconds(30),
        ]);
    }

    private function createEvent(Device $device, ?Refill $refill, string $type, string $severity, string $message): DeviceEvent
    {
        return DeviceEvent::create([
            'event_id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'refill_id' => $refill?->id,
            'type' => $type,
            'severity' => $severity,
            'message' => $message,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Pastikan botol berada di tatakan dan pembacaan timbangan sudah tenang
     * selama minimal durasi jeda stabilisasi (default 2000 ms) sebelum pompa aktif.
     */
    private function isBottlePlacementStable(Device $device, Telemetry $current, $setting): bool
    {
        $durationMs = (int) ($setting->stable_duration_ms ?? 2000);
        if ($durationMs <= 0) {
            $durationMs = 2000;
        }

        $range = (float) ($setting->stable_range_g ?? 3.0);
        if ($range <= 0) {
            $range = 3.0;
        }

        // Interval pengiriman telemetry ESP32 adalah ~500ms
        // Minimal butuh 4 sampel data stabil (sekitar 2 detik)
        $samplesNeeded = max(3, (int) ceil($durationMs / 500.0));

        $recent = Telemetry::where('device_id', $device->id)
            ->where('id', '<=', $current->id)
            ->orderBy('id', 'desc')
            ->limit($samplesNeeded + 1)
            ->get();

        if ($recent->count() < $samplesNeeded) {
            return false;
        }

        // 1. Semua sampel harus mendeteksi botol ada dan sensor siap
        foreach ($recent as $t) {
            if (!$t->bottle_present || !$t->sensor_ready || $t->total_weight_g === null) {
                return false;
            }
        }

        // 2. Pastikan durasi sejak sampel awal sudah mencapai $durationMs
        $oldest = $recent->last();
        $oldestTime = $oldest->received_at ?? $oldest->created_at;
        $currentTime = $current->received_at ?? $current->created_at;

        if ($oldestTime && $currentTime) {
            $diffMs = abs($currentTime->diffInMilliseconds($oldestTime));
            if ($diffMs < ($durationMs - 200)) {
                return false;
            }
        }

        // 3. Pastikan getaran/fluktuasi berat tidak melebihi batas rentang stabil
        $weights = $recent->pluck('total_weight_g');
        $minWeight = $weights->min();
        $maxWeight = $weights->max();

        if (($maxWeight - $minWeight) > $range) {
            return false;
        }

        return true;
    }
}
