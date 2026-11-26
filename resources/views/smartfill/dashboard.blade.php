@extends('smartfill.layout')
@section('title', 'Dashboard')
@section('crumb', 'Dashboard')
@section('content')
<div class="page-heading">
    <div>
        <div class="eyebrow">OVERVIEW / MONITORING</div>
        <h1>Dashboard</h1>
        <p>Pantau kondisi Smart Rack dan proses refill dalam satu tampilan.</p>
    </div>
    <div class="heading-meta">
        <span class="date-icon">▦</span>
        <div>
            <b>{{ now('Asia/Jakarta')->locale('id')->translatedFormat('l, d F Y') }}</b>
            <small>{{ now('Asia/Jakarta')->format('H:i') }} WIB · Waktu Jakarta</small>
        </div>
    </div>
</div>

<!-- KETERANGAN ATLET / SESI AKTIF (REQUEST #5) -->
<div class="panel" style="background:linear-gradient(135deg, #f0fdf9 0%, #e6f7f4 100%);border:1px solid #c8ece5;border-radius:12px;padding:16px 22px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;">
    <div style="display:flex;align-items:center;gap:15px;">
        <span style="width:46px;height:46px;border-radius:50%;background:#138f83;color:#fff;display:grid;place-items:center;font-size:18px;font-weight:800;flex-shrink:0;">
            {{ strtoupper(substr($session?->athlete?->name ?? '?', 0, 1)) }}
        </span>
        <div>
            <div style="font-size:10px;font-weight:700;letter-spacing:1.5px;color:#0e7268;">SESI ATLET AKTIF SAAT INI</div>
            <h2 style="margin:2px 0;font-size:19px;font-weight:800;color:#183b49;">{{ $session?->athlete?->name ?? 'Belum Ada Atlet Dipilih' }}</h2>
            <div style="font-size:12px;color:#537383;display:flex;gap:14px;flex-wrap:wrap;margin-top:3px;">
                <span>Kode: <b>{{ $session?->athlete?->code ?? '—' }}</b></span>
                <span>Botol: <b>{{ $session?->bottle?->name ?? '—' }} ({{ $session?->bottle?->code ?? '—' }})</b></span>
                <span>Kapasitas: <b>{{ number_format($session?->water_capacity_g ?? 700, 0) }} mL</b></span>
                <span>Berat Kosong: <b>{{ number_format($session?->bottle_empty_weight_g ?? 19, 1) }} g</b></span>
                @if($session?->started_at)
                    <span>Mulai Sesi: <b>{{ $session->started_at->timezone('Asia/Jakarta')->format('H:i') }} WIB</b></span>
                @endif
            </div>
        </div>
    </div>
    <a href="{{ route('athletes') }}" class="btn-primary" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
        <span>Ganti Sesi Atlet</span> ➔
    </a>
</div>

<div class="stat-grid">
    <!-- TOTAL REFILL PER ATLET (REQUEST #4) -->
    <article class="stat-card">
        <div class="stat-top"><span class="stat-icon blue">↻</span><span class="stat-label">TOTAL REFILL (ATLET)</span></div>
        <div class="stat-value" id="totalRefill">{{ $totalCount }}</div>
        <div class="stat-foot"><span class="trend neutral">Atlet Aktif</span><span>{{ $session?->athlete?->name ?? 'Seluruh waktu' }}</span></div>
    </article>
    <!-- REFILL HARI INI PER ATLET (REQUEST #4) -->
    <article class="stat-card">
        <div class="stat-top"><span class="stat-icon mint">◷</span><span class="stat-label">REFILL HARI INI (ATLET)</span></div>
        <div class="stat-value" id="todayRefill">{{ $todayCount }}</div>
        <div class="stat-foot"><span class="trend positive">Hari ini · WIB</span><span>{{ $session?->athlete?->name ?? 'Hari ini' }}</span></div>
    </article>
    <article class="stat-card">
        <div class="stat-top"><span class="stat-icon violet">⌁</span><span class="stat-label">KONEKSI ESP32</span></div>
        <div class="stat-value status-word {{ $online ? 'text-green' : 'text-red' }}" id="connectionText">{{ $online ? 'ONLINE' : 'OFFLINE' }}</div>
        <div class="stat-foot"><span class="connection-indicator {{ $online ? 'connected' : '' }}" id="connectionDot"></span><span id="lastSeen">{{ $device?->last_seen_at ? $device->last_seen_at->timezone('Asia/Jakarta')->diffForHumans() : 'Belum ada data' }}</span></div>
    </article>
</div>

<div class="dashboard-grid">
    <section class="panel water-panel">
        <div class="panel-header"><div><div class="eyebrow">SMART RACK / SLOT 01</div><h2>Persediaan Air</h2></div><span class="status-chip {{ $online ? 'chip-green' : 'chip-red' }}" id="rackStatus"><i></i>{{ $online ? 'Sistem normal' : 'Perangkat offline' }}</span></div>
        <div class="water-main">
            <div class="water-gauge" id="waterGauge" style="--value:{{ max(0,min(100,(float)($percent ?? 0))) }}%">
                <div class="gauge-inner"><div class="gauge-number"><span id="waterPercent">{{ $percent !== null ? number_format($percent, 0) : '--' }}</span><small>%</small></div><div class="gauge-caption">PERSENTASE AIR</div></div>
            </div>
            <div class="water-details">
                <span class="mini-label">STATUS AIR</span>
                <h3 id="waterStatus">{{ $waterStatus }}</h3>
                <p class="muted">Volume air terukur (Netto)</p>
                <div class="volume-value"><b id="waterVolume">{{ $volumeMl !== null ? number_format($volumeMl) : '--' }}</b><span>mL / {{ $capacity = $session?->water_capacity_g ?? $device?->setting?->bottle?->water_capacity_g ?? 0 }} mL</span></div>
                <div class="water-legend" style="margin-top:6px;"><span><i class="legend-green"></i>Air tersedia</span><span><i class="legend-gray"></i>Kapasitas kosong</span></div>
                <div style="margin-top:10px;font-size:11px;color:#788b97;background:#f8fafb;padding:6px 10px;border-radius:6px;display:flex;gap:12px;">
                    <div>Berat Timbangan: <b id="totalWeight" style="color:#203b4d;">{{ $totalWeightG !== null ? number_format($totalWeightG, 0) : '--' }} g</b></div>
                    <div>Botol Kosong: <span id="emptyWeight">{{ $emptyWeightG ?? 19 }} g</span></div>
                </div>
            </div>
        </div>
        <div class="water-divider"></div>
        <div class="rack-info-grid">
            <div class="rack-info"><span class="info-symbol">▣</span><div><small>STATUS BOTOL</small><b id="bottleStatus">{{ is_null($latest?->bottle_present) ? 'Belum diketahui' : ($latest->bottle_present ? 'TERPASANG' : 'TIDAK ADA') }}</b></div></div>
            <div class="rack-info"><span class="info-symbol pump">◉</span><div><small>STATUS POMPA</small><b id="pumpStatus">{{ $latest?->pump_on ? 'ON' : 'OFF' }}</b></div></div>
            <div class="rack-info"><span class="info-symbol">♙</span><div><small>PENGGUNA AKTIF</small><b id="sessionAthlete">{{ $session?->athlete?->name ?? 'Belum dipilih' }}</b></div></div>
            <div class="rack-info"><span class="info-symbol">▤</span><div><small>BOTOL AKTIF</small><b id="sessionBottle">{{ $session?->bottle?->code ?? '—' }}</b></div></div>
        </div>
    </section>

    <section class="panel refill-panel">
        <div class="panel-header"><div><div class="eyebrow">AUTOMATIC REFILL</div><h2>Proses Pengisian</h2></div><span class="status-chip {{ $activeRefill ? ($activeRefill->status === 'cancelled' ? 'chip-red' : 'chip-blue') : 'chip-gray' }}" id="refillState"><i></i>{{ $activeRefill ? strtoupper($activeRefill->status) : 'IDLE' }}</span></div>
        <div class="refill-illustration"><div class="bottle-visual"><div class="bottle-cap"></div><div class="bottle-neck"></div><div class="bottle-body"><div class="bottle-water" id="bottleWater" style="height:{{ max(0,min(100,(float)($percent ?? 0))) }}%"></div><span>H₂O</span></div></div><div class="refill-wave wave-one"></div><div class="refill-wave wave-two"></div></div>
        <div class="progress-head"><span>PROGRES MENUJU TARGET</span><b id="progressPercent">{{ $progress !== null ? number_format($progress, 0).'%' : '—' }}</b></div>
        <div class="progress-track"><div class="progress-fill" id="progressFill" style="width:{{ $progress ?? 0 }}%"></div></div>
        <div class="progress-foot"><span>Persediaan saat ini</span><b id="progressCurrent">{{ $percent !== null ? number_format($percent,0).'%' : '—' }}</b><span>Target berhenti</span><b id="progressTarget">{{ $activeRefill?->target_percent ? number_format($activeRefill->target_percent,0).'%' : ($device?->setting?->stop_percent ? number_format($device->setting->stop_percent,0).'%' : '—') }}</b></div>
        
        <!-- TOMBOL STOP REFILL (KENDALI DARURAT PELATIH / OPERATOR) -->
        <div style="margin-top:14px;display:flex;align-items:center;justify-content:space-between;gap:10px;background:#fef2f2;border:1px solid #fee2e2;padding:10px 14px;border-radius:8px;">
            <div style="font-size:12px;color:#991b1b;line-height:1.3;">
                <b>Kontrol Pelatih / Operator:</b><br>
                <span style="color:#b91c1c;font-size:11px;">Hentikan pengisian seketika jika diperlukan.</span>
            </div>
            <form action="{{ route('device.force-stop') }}" method="POST" id="formStopRefill" style="margin:0;">
                @csrf
                <input type="hidden" name="rack" value="rack01">
                <button type="submit" id="btnStopRefill" class="btn-primary" style="background:#dc2626;border-color:#b91c1c;color:#fff;padding:8px 16px;font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:6px;border-radius:6px;cursor:pointer;transition:all .2s;" {{ ($activeRefill && in_array($activeRefill->status, ['filling', 'paused', 'pending'])) || ($latest?->pump_on) ? '' : 'disabled' }}>
                    <span>⏹</span> Stop Refill
                </button>
            </form>
        </div>

        <div class="safe-note" style="margin-top:12px;"><span>✦</span><p><b>Kontrol otomatis aktif</b><br>Keputusan pompa dan pengamanan refill dijalankan oleh middleware/backend IoT.</p></div>
    </section>
</div>

<div class="lower-grid">
    <!-- RIWAYAT REFILL TERBARU PER ATLET & WAKTU WIB (REQUEST #6) -->
    <section class="panel table-panel">
        <div class="panel-header">
            <div>
                <div class="eyebrow">ACTIVITY</div>
                <h2>Riwayat Refill Terbaru</h2>
            </div>
            <span class="subtle-count" style="font-size:11px;color:#7e8f99;">Atlet: <b id="historyAthleteName">{{ $session?->athlete?->name ?? 'Semua' }}</b></span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>WAKTU (WIB)</th>
                        <th>PENGGUNA</th>
                        <th>BOTOL</th>
                        <th>HASIL</th>
                        <th>DURASI</th>
                    </tr>
                </thead>
                <tbody id="recentRefillsBody">
                @forelse($recentRefills as $refill)
                    <tr>
                        <td>
                            <b>{{ $refill->started_at?->timezone('Asia/Jakarta')->format('H:i') ?? '—' }} WIB</b>
                            <small>{{ $refill->started_at?->timezone('Asia/Jakarta')->format('d/m/Y') ?? '—' }}</small>
                        </td>
                        <td>{{ $refill->athlete?->name ?? '—' }}</td>
                        <td>{{ $refill->bottle?->code ?? '—' }}</td>
                        <td>
                            <span class="result-pill {{ $refill->status === 'completed' ? 'result-success' : (in_array($refill->status,['failed','cancelled']) ? 'result-failed' : 'result-pending') }}">
                                {{ strtoupper($refill->status) }}
                            </span>
                        </td>
                        <td>{{ $refill->started_at && $refill->finished_at ? $refill->started_at->diffInSeconds($refill->finished_at).' dtk' : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty-state">Belum ada riwayat refill untuk atlet ini.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <!-- AKTIVITAS GANGGUAN PER ATLET TANPA LINK LIHAT LOG (REQUEST #3) -->
    <section class="panel alerts-panel">
        <div class="panel-header">
            <div>
                <div class="eyebrow">SYSTEM HEALTH</div>
                <h2>Aktivitas Gangguan</h2>
            </div>
            <span class="subtle-count" style="font-size:11px;color:#7e8f99;">Atlet: <b id="alertsAthleteName">{{ $session?->athlete?->name ?? 'Aktif' }}</b></span>
        </div>
        <div class="alert-list" id="recentEventsList">
        @forelse($recentEvents as $event)
            <div class="alert-item">
                <span class="alert-mark {{ $event->severity === 'error' ? 'mark-red' : ($event->severity === 'warning' ? 'mark-amber' : 'mark-blue') }}">!</span>
                <div>
                    <b>{{ str_replace('_',' ',ucfirst($event->type)) }}</b>
                    <p>{{ $event->message }}</p>
                    <small>{{ $event->occurred_at?->timezone('Asia/Jakarta')->format('H:i:s · d M Y') }} WIB</small>
                </div>
                <span class="severity">{{ strtoupper($event->severity) }}</span>
            </div>
        @empty
            <div class="empty-alert">
                <span>✓</span>
                <b>Semua terkendali</b>
                <small>Tidak ada gangguan pada sesi atlet ini.</small>
            </div>
        @endforelse
        </div>
    </section>
</div>

<div class="data-footnote">
    <span class="live-dot"></span> Data sensor diperbarui otomatis setiap 2,5 detik.
    <span id="lastUpdated">Pembaruan terakhir: {{ $updatedAt?->timezone('Asia/Jakarta')->format('H:i:s') ?? now('Asia/Jakarta')->format('H:i:s') }} WIB</span>
</div>
@endsection

@push('scripts')
<script>
window.SmartFillConfig = { statusUrl: @json(route('api.status')), pollMs: 2500 };
</script>
@endpush
