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

        // 3. Ambil Siklus Refill yang Sedang Berjalan (jika ada)
        $activeRefill = Refill::where('device_id', $device->id)
            ->where('status', 'filling')
            ->latest('id')
            ->first();

        // -------------------------------------------------------------
        // KASUS A: SEDANG ADA PENGISIAN AIR BERJALAN (FILLING)
        // -------------------------------------------------------------
        if ($activeRefill) {
            // A1. Pengamanan: Botol diangkat saat refill
            if (!$bottlePresent) {
                $activeRefill->update([
                    'status' => 'cancelled',
                    'finish_reason' => 'BOTOL_DIANGKAT',
                    'finished_at' => now(),
                    'end_total_weight_g' => $totalWeight,
                ]);

                $this->createEvent($device, $activeRefill, 'bottle_removed', 'warning', 'Botol diangkat saat pengisian! Pompa dihentikan.');
                $commandsToSend[] = $this->createCommand($device, $activeRefill, 'set_pump', ['pump' => false]);
                return $commandsToSend;
            }

            // A2. Pengamanan: Batas Waktu Maksimum Refill (T_max = 60 detik)
            $maxDurationSec = 60;
            if ($activeRefill->started_at && now()->diffInSeconds($activeRefill->started_at) >= $maxDurationSec) {
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

            // A3. Target Tercapai (W_target / stop_percent tercapai)
            $stopPercent = (float) ($setting->stop_percent ?? 80.0);
            $targetWeight = (float) $activeRefill->target_total_weight_g;

            if (
                ($waterPercent !== null && $waterPercent >= $stopPercent) ||
                ($totalWeight !== null && $targetWeight > 0 && $totalWeight >= $targetWeight)
            ) {
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

        // Cek apakah botol sedang diangkat
        $setting = $device->setting;
        $bottleRemovedG = $setting ? (float) $setting->bottle_removed_g : 9.0;
        if ($latest->total_weight_g !== null && $latest->total_weight_g < $bottleRemovedG) {
            return [
                'status' => 'DIANGKAT',
                'color' => 'orange',
                'badge' => 'DIANGKAT',
                'description' => 'Botol tidak berada di atas tatakan timbangan.',
            ];
        }

        // Cek apakah sedang proses refill
        $activeRefill = Refill::where('device_id', $device->id)
            ->where('status', 'filling')
            ->first();

        if ($activeRefill) {
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
}
