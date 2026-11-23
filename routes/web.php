<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// Halaman Utama & Dashboard Monitoring
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');
Route::get('/monitor', fn () => redirect()->route('dashboard'))->name('monitor');

// Endpoint Realtime Polling Status untuk smartfill.js
Route::get('/api/status', [DashboardController::class, 'apiStatus'])->name('api.status');

// Halaman Riwayat Refill
Route::get('/riwayat', [DashboardController::class, 'history'])->name('history');

// Halaman Log Gangguan dialihkan ke Dashboard (Aktivitas gangguan ditampilkan langsung per atlet di Dashboard)
Route::get('/gangguan', fn () => redirect()->route('dashboard'))->name('alerts');
Route::post('/gangguan/akui', [DashboardController::class, 'acknowledgeAlarm'])->name('alerts.acknowledge');

// Halaman Manajemen Data Atlet & Botol
Route::get('/atlet', [DashboardController::class, 'athletes'])->name('athletes');
Route::post('/atlet', [DashboardController::class, 'storeAthlete'])->name('athletes.store');
Route::match(['put', 'post'], '/atlet/{athlete}', [DashboardController::class, 'updateAthlete'])->name('athletes.update');
Route::post('/sesi', [DashboardController::class, 'switchSession'])->name('session.switch');

// Halaman Pengaturan Ambang & Kalibrasi
Route::get('/pengaturan', function () {
    $device = \App\Models\Device::with('setting.bottle')->where('code', 'rack01')->first();
    $unresolved = $device ? \App\Models\DeviceEvent::where('device_id', $device->id)->whereNull('resolved_at')->count() : 0;
    return view('smartfill.settings', compact('device', 'unresolved'));
})->name('settings');
Route::post('/pengaturan', [DashboardController::class, 'updateSettings'])->name('settings.update');
Route::post('/pengaturan/kalibrasi', [DashboardController::class, 'calibrateScale'])->name('settings.calibrate');
Route::post('/pengaturan/botol', [DashboardController::class, 'updateBottleWeight'])->name('settings.bottle');

// Aksi Manual Hardware / Pengujian
Route::post('/device/force-stop', [DashboardController::class, 'forceStopPump'])->name('device.force-stop');
Route::post('/device/test-pump', [DashboardController::class, 'testPump'])->name('device.test-pump');
Route::post('/device/tare', [DashboardController::class, 'tareScale'])->name('device.tare');
