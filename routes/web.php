<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// Dashboard Pelatih & Tim Utama (Sesuai Gambar 10 Laporan)
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/monitor', [DashboardController::class, 'index'])->name('monitor');

// API Endpoint untuk polling live status
Route::get('/api/status', [DashboardController::class, 'apiStatus'])->name('api.status');

// Aksi Kontrol Operator
Route::post('/session/toggle-refill', [DashboardController::class, 'toggleRefill'])->name('session.toggle-refill');
Route::post('/session/switch', [DashboardController::class, 'switchSession'])->name('session.switch');
Route::post('/device/reset-alarm', [DashboardController::class, 'resetAlarm'])->name('device.reset-alarm');
Route::post('/device/force-stop', [DashboardController::class, 'forceStopPump'])->name('device.force-stop');
Route::post('/device/test-pump', [DashboardController::class, 'testPump'])->name('device.test-pump');
Route::post('/device/tare', [DashboardController::class, 'tareScale'])->name('device.tare');

// Halaman debug lama untuk pesan uji MQTT
Route::get('/mqtt-raw', function () {
    $messages = \App\Models\MqttMessage::query()
        ->where('device_id', 'rack01')
        ->latest('id')
        ->limit(20)
        ->get();

    $totalMessages = \App\Models\MqttMessage::query()
        ->where('device_id', 'rack01')
        ->count();

    return view('monitor', compact('messages', 'totalMessages'));
});
