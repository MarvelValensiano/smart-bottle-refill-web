<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('athletes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('bottles', function (Blueprint $table) {
            $table->foreignId('athlete_id')
                ->nullable()
                ->after('name')
                ->constrained('athletes')
                ->nullOnDelete();
        });

        Schema::create('usage_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_code', 50)->unique();
            $table->foreignId('device_id')
                ->constrained('devices')
                ->restrictOnDelete();
            $table->foreignId('athlete_id')
                ->constrained('athletes')
                ->restrictOnDelete();
            $table->foreignId('bottle_id')
                ->constrained('bottles')
                ->restrictOnDelete();

            // active, completed, cancelled
            $table->string('status', 20)->default('active');
            $table->boolean('refill_permitted')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->string('lock_reason', 100)->nullable();

            $table->decimal('bottle_empty_weight_g', 10, 3);
            $table->decimal('water_capacity_g', 10, 3);

            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'status']);
        });

        Schema::table('refills', function (Blueprint $table) {
            $table->foreignId('athlete_id')
                ->nullable()
                ->after('bottle_id')
                ->constrained('athletes')
                ->nullOnDelete();
            $table->foreignId('session_id')
                ->nullable()
                ->after('athlete_id')
                ->constrained('usage_sessions')
                ->nullOnDelete();
            $table->boolean('is_success_counted')->default(false)->after('finish_reason');
        });
    }

    public function down(): void
    {
        Schema::table('refills', function (Blueprint $table) {
            $table->dropForeign(['session_id']);
            $table->dropForeign(['athlete_id']);
            $table->dropColumn(['session_id', 'athlete_id', 'is_success_counted']);
        });

        Schema::dropIfExists('usage_sessions');

        Schema::table('bottles', function (Blueprint $table) {
            $table->dropForeign(['athlete_id']);
            $table->dropColumn('athlete_id');
        });

        Schema::dropIfExists('athletes');
    }
};
