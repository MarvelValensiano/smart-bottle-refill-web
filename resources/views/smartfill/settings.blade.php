@extends('smartfill.layout')
@section('title','Pengaturan')
@section('crumb','Pengaturan')
@section('content')
<div class="page-heading"><div><div class="eyebrow">CONFIGURATION / DEVICE</div><h1>Pengaturan</h1><p>Parameter pemantauan dan batas deteksi untuk Smart Rack 01.</p></div><span class="status-chip chip-blue"><i></i>Rack 01</span></div>
@if(!$device || !$device->setting)
<div class="panel empty-alert large"><span>!</span><b>Konfigurasi perangkat belum tersedia</b><small>Jalankan migrasi dan seeder backend terlebih dahulu.</small></div>
@else
<form method="POST" action="{{ route('settings.update') }}" class="panel settings-form">@csrf
<div class="panel-header"><div><div class="eyebrow">REFILL THRESHOLDS</div><h2>Ambang Pengisian</h2></div><span class="setting-lock">Backend IoT menggunakan parameter ini</span></div>
<div class="settings-grid">
<label>Ambang mulai refill (%)<input type="number" min="0" max="100" step="0.1" name="start_percent" value="{{ old('start_percent',$device->setting->start_percent) }}" required><small>Refill dapat dipicu saat persentase air mencapai atau di bawah nilai ini.</small></label>
<label>Target berhenti (%)<input type="number" min="0" max="100" step="0.1" name="stop_percent" value="{{ old('stop_percent',$device->setting->stop_percent) }}" required><small>Target penghentian otomatis yang dievaluasi middleware.</small></label>
</div>
<div class="section-separator"></div><div class="panel-header"><div><div class="eyebrow">SAFETY / SENSOR</div><h2>Deteksi Gangguan</h2></div></div>
<div class="settings-grid">
<label>Toleransi nol (gram)<input type="number" min="0" step="0.1" name="zero_tolerance_g" value="{{ old('zero_tolerance_g',$device->setting->zero_tolerance_g) }}" required><small>Batas pembacaan yang dianggap mendekati nol.</small></label>
<label>Ambang botol terpasang (gram)<input type="number" min="0" step="0.1" name="bottle_present_g" value="{{ old('bottle_present_g',$device->setting->bottle_present_g) }}" required><small>Ambang deteksi botol pada tatakan.</small></label>
<label>Ambang botol diangkat (gram)<input type="number" min="0" step="0.1" name="bottle_removed_g" value="{{ old('bottle_removed_g',$device->setting->bottle_removed_g) }}" required><small>Nilai histeresis untuk mendeteksi botol dilepas.</small></label>
<label>Timeout tanpa kenaikan (ms)<input type="number" min="1000" step="100" name="no_gain_timeout_ms" value="{{ old('no_gain_timeout_ms',$device->setting->no_gain_timeout_ms) }}" required><small>Durasi maksimum tanpa kenaikan berat yang terdeteksi.</small></label>
<label>Kenaikan berat minimum (gram)<input type="number" min="0" step="0.1" name="min_gain_g" value="{{ old('min_gain_g',$device->setting->min_gain_g) }}" required><small>Kenaikan minimum untuk menganggap air bertambah.</small></label>
<label>Jeda stabilisasi botol (ms)<input type="number" min="500" max="10000" step="100" name="stable_duration_ms" value="{{ old('stable_duration_ms', $device->setting->stable_duration_ms ?? 2000) }}" required><small>Waktu tunggu agar posisi botol tenang & fix sebelum pompa mulai nyala (default 2000 ms = 2 detik).</small></label>
</div>
<div class="settings-note"><b>Catatan keselamatan</b><p>Halaman ini hanya memperbarui parameter konfigurasi. Website tidak menyalakan atau mematikan pompa dan tidak menentukan keputusan refill secara real-time. Semua keputusan aktuator tetap berada pada middleware/backend IoT dan firmware pengaman.</p></div>
<div class="form-actions"><button class="btn-primary" type="submit">Simpan pengaturan</button></div>
</form>
<div class="panel calibration-panel" style="margin-bottom:18px;">
    <div class="panel-header"><div><div class="eyebrow">SCALE CALIBRATION</div><h2>Kalibrasi Timbangan & Samakan dengan Timbangan Asli</h2></div><span class="status-chip chip-blue">Presisi</span></div>
    
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;padding:15px 0;">
        <div style="background:#f9fbfb;border:1px solid #e1e9ec;border-radius:10px;padding:16px;">
            <b style="font-size:12px;display:block;margin-bottom:6px;">Langkah 1: Tare Titik Nol Tatakan</b>
            <p style="font-size:11px;color:#71828d;margin:0 0 12px;">Kosongkan tatakan (angkat botol dan semua benda), lalu klik tombol di bawah untuk menyetel pembacaan tatakan ke 0 gram.</p>
            <form method="POST" action="{{ route('device.tare') }}">@csrf
                <button type="submit" class="btn-primary" style="background:#2c7a7b;">⟳ Tare Titik Nol Sekarang (0g)</button>
            </form>
        </div>

        <div style="background:#f9fbfb;border:1px solid #e1e9ec;border-radius:10px;padding:16px;">
            <b style="font-size:12px;display:block;margin-bottom:6px;">Langkah 2: Kalibrasi Beban Asli</b>
            <p style="font-size:11px;color:#71828d;margin:0 0 12px;">Timbang botol/beban di timbangan digital Anda, lalu taruh di atas alat dan masukkan berat aslinya di bawah:</p>
            <form method="POST" action="{{ route('settings.calibrate') }}" style="display:flex;gap:10px;">@csrf
                <input type="number" step="0.1" name="known_weight_g" placeholder="Berat asli (contoh: 150.0)" required style="border:1px solid #ccd7dc;border-radius:6px;padding:8px 10px;font-size:12px;flex:1;">
                <button type="submit" class="btn-primary">Kalibrasikan Sensor</button>
            </form>
        </div>
    </div>

    <div style="background:#f9fbfb;border:1px solid #e1e9ec;border-radius:10px;padding:16px;margin-top:10px;">
        <b style="font-size:12px;display:block;margin-bottom:6px;">Langkah 3: Karakteristik Botol yang Digunakan</b>
        <p style="font-size:11px;color:#71828d;margin:0 0 12px;">Sesuaikan berat botol saat benar-benar kosong agar perhitungan berat air murni (netto) akurat:</p>
        <form method="POST" action="{{ route('settings.bottle') }}" style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end;">@csrf
            <label style="font-size:11px;display:grid;gap:4px;color:#61727d;">
                Berat Botol Kosong (gram)
                <input type="number" step="0.1" name="empty_weight_g" value="{{ $device->setting->bottle?->empty_weight_g ?? 19 }}" required style="border:1px solid #ccd7dc;border-radius:6px;padding:8px 10px;font-size:12px;">
            </label>
            <label style="font-size:11px;display:grid;gap:4px;color:#61727d;">
                Kapasitas Air Botol (mL)
                <input type="number" step="1" name="water_capacity_g" value="{{ $device->setting->bottle?->water_capacity_g ?? 700 }}" required style="border:1px solid #ccd7dc;border-radius:6px;padding:8px 10px;font-size:12px;">
            </label>
            <button type="submit" class="btn-primary" style="height:35px;">Simpan Botol</button>
        </form>
    </div>
</div>

<div class="panel calibration-panel"><div class="panel-header"><div><div class="eyebrow">DEVICE PROFILE</div><h2>Profil Kalibrasi Aktif</h2></div><span class="status-chip chip-gray">Informasi</span></div><div class="calibration-grid"><div><small>Perangkat</small><b>{{ $device->name }} ({{ $device->code }})</b></div><div><small>Botol default</small><b>{{ $device->setting->bottle?->name ?? '—' }} ({{ $device->setting->bottle?->empty_weight_g ?? 19 }}g)</b></div><div><small>Faktor kalibrasi</small><b>{{ number_format($device->setting->calibration_factor, 4) }}</b></div><div><small>Tare offset</small><b>{{ $device->setting->tare_offset ?? '0' }} ({{ $device->setting->tared_at?->format('d M H:i') ?? 'Belum' }})</b></div></div></div>
@endif
@endsection
