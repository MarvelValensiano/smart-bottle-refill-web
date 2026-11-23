<?php

namespace Database\Seeders;

use App\Models\Athlete;
use App\Models\Bottle;
use App\Models\Device;
use App\Models\DeviceSetting;
use App\Models\Refill;
use App\Models\UsageSession;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SmartRefillSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $now = now();

            // 1. Device rack01
            $device = Device::firstOrCreate(
                ['code' => 'rack01'],
                [
                    'name' => 'Smart Refill — Slot 1',
                    'last_seen_at' => $now,
                ]
            );

            // 2. Atlet Sesuai Gambar 10 Laporan
            $atletA = Athlete::firstOrCreate(
                ['code' => 'ATL-001'],
                ['name' => 'Atlet A (Marvel)', 'is_active' => true]
            );

            $atletB = Athlete::firstOrCreate(
                ['code' => 'ATL-002'],
                ['name' => 'Atlet B (Grace)', 'is_active' => true]
            );

            $atletC = Athlete::firstOrCreate(
                ['code' => 'ATL-003'],
                ['name' => 'Atlet C (Kholiq)', 'is_active' => true]
            );

            // 3. Botol-botol Atlet
            $botolA = Bottle::firstOrCreate(
                ['code' => 'BTL-001'],
                [
                    'name' => 'Botol 750mL Atlet A',
                    'athlete_id' => $atletA->id,
                    'empty_weight_g' => 150.0,
                    'water_capacity_g' => 750.0,
                ]
            );

            $botolB = Bottle::firstOrCreate(
                ['code' => 'BTL-002'],
                [
                    'name' => 'Botol 600mL Atlet B',
                    'athlete_id' => $atletB->id,
                    'empty_weight_g' => 120.0,
                    'water_capacity_g' => 600.0,
                ]
            );

            $botolC = Bottle::firstOrCreate(
                ['code' => 'BTL-003'],
                [
                    'name' => 'Botol 800mL Atlet C',
                    'athlete_id' => $atletC->id,
                    'empty_weight_g' => 140.0,
                    'water_capacity_g' => 800.0,
                ]
            );

            // Botol default bawaan kalibrasi
            $botolUji = Bottle::firstOrCreate(
                ['code' => 'aquviva-01'],
                [
                    'name' => 'Botol Uji Aquviva',
                    'athlete_id' => $atletA->id,
                    'empty_weight_g' => 19.0,
                    'water_capacity_g' => 700.0,
                ]
            );

            // 4. Konfigurasi Device
            $setting = DeviceSetting::firstOrCreate(
                ['device_id' => $device->id],
                [
                    'bottle_id' => $botolA->id,
                    'calibration_factor' => 1070.682373,
                    'tare_offset' => 0.0,
                    'tared_at' => $now,
                    'start_percent' => 20.0, // Sesuai laporan: ambang mulai refill <= 20%
                    'stop_percent' => 95.0,  // Sesuai laporan: target usulan 95%
                    'zero_tolerance_g' => 2.0,
                    'bottle_present_g' => 14.0,
                    'bottle_removed_g' => 9.0,
                    'stable_range_g' => 3.0,
                    'stable_duration_ms' => 2000,
                    'sensor_timeout_ms' => 2000,
                    'no_gain_timeout_ms' => 5000, // T_no_change = 5 detik
                    'min_gain_g' => 2.0,          // ΔW_min = 2 gram
                    'weight_drop_g' => 10.0,
                    'retry_delay_ms' => 5000,
                    'auto_enabled' => true,
                ]
            );

            // 5. Sesi Aktif di Slot (SES-021)
            $activeSession = UsageSession::where('device_id', $device->id)
                ->where('status', 'active')
                ->first();

            if (!$activeSession) {
                UsageSession::create([
                    'session_code' => 'SES-021',
                    'device_id' => $device->id,
                    'athlete_id' => $atletA->id,
                    'bottle_id' => $botolA->id,
                    'status' => 'active',
                    'refill_permitted' => true,
                    'is_locked' => false,
                    'bottle_empty_weight_g' => $botolA->empty_weight_g,
                    'water_capacity_g' => $botolA->water_capacity_g,
                    'started_at' => $now->subMinutes(30),
                ]);
            }

            // 6. Riwayat Refill Sample (Sesuai Gambar 10: RFL-003, RFL-004, RFL-005)
            if (Refill::count() === 0) {
                Refill::create([
                    'refill_id' => (string) Str::uuid(),
                    'device_id' => $device->id,
                    'bottle_id' => $botolC->id,
                    'athlete_id' => $atletC->id,
                    'status' => 'completed',
                    'bottle_empty_weight_g' => $botolC->empty_weight_g,
                    'water_capacity_g' => $botolC->water_capacity_g,
                    'target_percent' => 95.0,
                    'target_total_weight_g' => 900.0,
                    'start_total_weight_g' => 250.0,
                    'end_total_weight_g' => 895.0,
                    'added_water_g' => 645.0,
                    'started_at' => $now->copy()->subHours(2),
                    'finished_at' => $now->copy()->subHours(2)->addSeconds(40),
                    'pump_off_confirmed_at' => $now->copy()->subHours(2)->addSeconds(42),
                    'finish_reason' => 'TARGET_REACHED',
                    'is_success_counted' => true,
                ]);

                Refill::create([
                    'refill_id' => (string) Str::uuid(),
                    'device_id' => $device->id,
                    'bottle_id' => $botolA->id,
                    'athlete_id' => $atletA->id,
                    'status' => 'completed',
                    'bottle_empty_weight_g' => $botolA->empty_weight_g,
                    'water_capacity_g' => $botolA->water_capacity_g,
                    'target_percent' => 95.0,
                    'target_total_weight_g' => 862.5,
                    'start_total_weight_g' => 280.0,
                    'end_total_weight_g' => 860.0,
                    'added_water_g' => 580.0,
                    'started_at' => $now->copy()->subHours(1),
                    'finished_at' => $now->copy()->subHours(1)->addSeconds(35),
                    'pump_off_confirmed_at' => $now->copy()->subHours(1)->addSeconds(37),
                    'finish_reason' => 'TARGET_REACHED',
                    'is_success_counted' => true,
                ]);
            }
        });
    }
}
