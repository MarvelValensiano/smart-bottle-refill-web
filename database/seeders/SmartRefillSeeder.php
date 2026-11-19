<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SmartRefillSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $now = now();

            $device = DB::table('devices')
                ->where('code', 'rack01')
                ->first();

            if ($device) {
                $deviceId = $device->id;
            } else {
                $deviceId = DB::table('devices')->insertGetId([
                    'code' => 'rack01',
                    'name' => 'Smart Refill — Slot 1',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $bottle = DB::table('bottles')
                ->where('code', 'aquviva-01')
                ->first();

            if ($bottle) {
                $bottleId = $bottle->id;
            } else {
                $bottleId = DB::table('bottles')->insertGetId([
                    'code' => 'aquviva-01',
                    'name' => 'Botol Uji Aquviva',
                    'empty_weight_g' => 19,
                    'water_capacity_g' => 700,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $settingsExist = DB::table('device_settings')
                ->where('device_id', $deviceId)
                ->exists();

            // Tidak menimpa konfigurasi/tare jika seeder diulang.
            if (!$settingsExist) {
                DB::table('device_settings')->insert([
                    'device_id' => $deviceId,
                    'bottle_id' => $bottleId,
                    'calibration_factor' => 1070.682373,
                    'tare_offset' => null,
                    'tared_at' => null,
                    'start_percent' => 30,
                    'stop_percent' => 80,
                    'zero_tolerance_g' => 2,
                    'bottle_present_g' => 14,
                    'bottle_removed_g' => 9,
                    'stable_range_g' => 3,
                    'stable_duration_ms' => 2000,
                    'sensor_timeout_ms' => 2000,
                    'no_gain_timeout_ms' => 5000,
                    'min_gain_g' => 2,
                    'weight_drop_g' => 10,
                    'retry_delay_ms' => 5000,
                    'auto_enabled' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }
}