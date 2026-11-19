<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();

            $table->string('code', 50)->unique();
            $table->string('name', 100);

            // Diisi ketika pesan perangkat diterima server.
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();
        });

        Schema::create('bottles', function (Blueprint $table) {
            $table->id();

            $table->string('code', 50)->unique();
            $table->string('name', 100);

            $table->decimal('empty_weight_g', 10, 3);
            $table->decimal('water_capacity_g', 10, 3);

            $table->timestamps();
        });

        Schema::create('device_settings', function (Blueprint $table) {
            $table->id();

            // Satu konfigurasi untuk setiap perangkat.
            $table->foreignId('device_id')
                ->unique()
                ->constrained('devices')
                ->restrictOnDelete();

            // Profil botol yang digunakan perangkat.
            $table->foreignId('bottle_id')
                ->constrained('bottles')
                ->restrictOnDelete();

            // Faktor dapat negatif sesuai arah pemasangan loadcell.
            $table->double('calibration_factor');

            // Null berarti server belum memiliki hasil tare.
            $table->double('tare_offset')->nullable();
            $table->timestamp('tared_at')->nullable();

            $table->decimal('start_percent', 5, 2)->default(30);
            $table->decimal('stop_percent', 5, 2)->default(80);

            $table->decimal('zero_tolerance_g', 8, 3)->default(2);
            $table->decimal('bottle_present_g', 8, 3)->default(14);
            $table->decimal('bottle_removed_g', 8, 3)->default(9);

            $table->decimal('stable_range_g', 8, 3)->default(3);
            $table->unsignedInteger('stable_duration_ms')->default(2000);

            $table->unsignedInteger('sensor_timeout_ms')->default(2000);

            $table->unsignedInteger('no_gain_timeout_ms')->default(5000);
            $table->decimal('min_gain_g', 8, 3)->default(2);
            $table->decimal('weight_drop_g', 8, 3)->default(10);
            $table->unsignedInteger('retry_delay_ms')->default(5000);

            // Otomatis tidak langsung aktif saat pertama disiapkan.
            $table->boolean('auto_enabled')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Hapus tabel yang memiliki foreign key terlebih dahulu.
        Schema::dropIfExists('device_settings');
        Schema::dropIfExists('bottles');
        Schema::dropIfExists('devices');
    }
};