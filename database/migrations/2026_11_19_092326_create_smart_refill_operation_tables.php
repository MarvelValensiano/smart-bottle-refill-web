<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('device_id')
                ->constrained('devices')
                ->restrictOnDelete();

            // Identitas boot berubah setiap ESP32 restart.
            $table->uuid('boot_id');

            // Nomor pesan naik selama satu boot.
            $table->unsignedBigInteger('sequence');

            // Waktu berjalan perangkat, bukan jam kalender.
            $table->unsignedBigInteger('uptime_ms');

            // Data langsung dari ESP32.
            $table->boolean('sensor_ready');
            $table->integer('raw_weight')->nullable();

            // Status output yang dilaporkan ESP32.
            // Ini konfirmasi kondisi GPIO, bukan bukti air mengalir.
            $table->boolean('pump_on')->default(false);

            // Hasil perhitungan SERVER.
            // Null jika belum tare atau pembacaan tidak valid.
            $table->decimal('total_weight_g', 12, 3)->nullable();
            $table->decimal('water_weight_g', 12, 3)->nullable();
            $table->decimal('water_percent', 8, 3)->nullable();
            $table->boolean('bottle_present')->nullable();

            // Konfigurasi yang dipakai saat menghitung.
            $table->double('calibration_factor')->nullable();
            $table->double('tare_offset')->nullable();

            // Pesan asli untuk penelusuran masalah.
            $table->json('payload');

            $table->timestamp('received_at', 3);
            $table->timestamps();

            // Pesan yang dikirim ulang tidak disimpan dua kali.
            $table->unique(
                ['device_id', 'boot_id', 'sequence'],
                'telemetries_message_unique'
            );

            $table->index(['device_id', 'received_at']);
        });

        Schema::create('refills', function (Blueprint $table) {
            $table->id();

            // Dibuat SERVER ketika satu siklus pengisian dimulai.
            $table->uuid('refill_id')->unique();

            $table->foreignId('device_id')
                ->constrained('devices')
                ->restrictOnDelete();

            $table->foreignId('bottle_id')
                ->constrained('bottles')
                ->restrictOnDelete();

            // pending, filling, paused, completed, cancelled, failed
            $table->string('status', 20)->default('pending');

            // Snapshot agar riwayat tidak berubah saat profil diedit.
            $table->decimal('bottle_empty_weight_g', 10, 3);
            $table->decimal('water_capacity_g', 10, 3);
            $table->decimal('target_percent', 5, 2);
            $table->decimal('target_total_weight_g', 12, 3);

            $table->decimal('start_total_weight_g', 12, 3);
            $table->decimal('end_total_weight_g', 12, 3)->nullable();

            // Estimasi kenaikan berat, bukan pengukuran flow meter.
            $table->decimal('added_water_g', 12, 3)->nullable();

            $table->unsignedInteger('retry_count')->default(0);

            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('finished_at', 3)->nullable();

            // Diisi setelah ESP32 mengonfirmasi pompa OFF.
            $table->timestamp('pump_off_confirmed_at', 3)->nullable();

            $table->string('finish_reason', 100)->nullable();

            $table->timestamps();

            $table->index(['device_id', 'status']);
            $table->index(['bottle_id', 'started_at']);
        });

        Schema::create('device_commands', function (Blueprint $table) {
            $table->id();

            $table->uuid('command_id')->unique();

            $table->foreignId('device_id')
                ->constrained('devices')
                ->restrictOnDelete();

            $table->foreignId('refill_id')
                ->nullable()
                ->constrained('refills')
                ->restrictOnDelete();

            // Boot perangkat yang dituju perintah.
            $table->uuid('target_boot_id');

            // Urutan perintah untuk menolak perintah lama.
            $table->unsignedBigInteger('command_sequence');

            // Nomor telemetry yang menjadi dasar perintah.
            $table->unsignedBigInteger('based_on_sequence')->nullable();

            // Contoh: set_outputs.
            $table->string('type', 40);

            // Payload output: pompa, LED, buzzer, LCD.
            $table->json('payload');

            // pending, published, acknowledged, expired, failed
            $table->string('status', 20)->default('pending');

            // Masa berlaku izin pompa pada perangkat.
            $table->unsignedInteger('lease_ms')->default(0);

            $table->timestamp('expires_at', 3);
            $table->timestamp('published_at', 3)->nullable();
            $table->timestamp('acknowledged_at', 3)->nullable();

            $table->json('ack_payload')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->unique(
                ['device_id', 'target_boot_id', 'command_sequence'],
                'device_commands_sequence_unique'
            );

            $table->index(['device_id', 'status']);
        });

        Schema::create('device_events', function (Blueprint $table) {
            $table->id();

            $table->uuid('event_id')->unique();

            $table->foreignId('device_id')
                ->constrained('devices')
                ->restrictOnDelete();

            $table->foreignId('refill_id')
                ->nullable()
                ->constrained('refills')
                ->restrictOnDelete();

            // Contoh: bottle_removed, no_weight_gain, sensor_offline.
            $table->string('type', 60);

            // info, warning, error
            $table->string('severity', 20)->default('info');

            $table->string('message', 255);
            $table->json('context')->nullable();

            $table->timestamp('occurred_at', 3);
            $table->timestamp('resolved_at', 3)->nullable();

            $table->timestamps();

            $table->index(['device_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_events');
        Schema::dropIfExists('device_commands');
        Schema::dropIfExists('refills');
        Schema::dropIfExists('telemetries');
    }
};