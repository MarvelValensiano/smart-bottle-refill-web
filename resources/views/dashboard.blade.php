<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Water Bottle Refill Dispenser — IoT Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0f3e5d;
            --primary-dark: #0a2a40;
            --primary-light: #e8f3fa;
            --accent-blue: #0284c7;
            --accent-green: #10b981;
            --accent-amber: #f59e0b;
            --accent-red: #ef4444;
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        body {
            background-color: var(--bg);
            color: var(--text-main);
            padding: 24px;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* HEADER */
        header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 26px 32px;
            border-radius: 18px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 10px 25px -5px rgba(15, 62, 93, 0.25);
        }

        .header-title h1 {
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -0.5px;
            margin-bottom: 4px;
        }

        .header-title p {
            color: #94a3b8;
            font-size: 13px;
        }

        .header-badge {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(8px);
            padding: 10px 18px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-align: right;
        }

        .header-badge .tag {
            font-size: 11px;
            font-weight: 700;
            color: #86efac;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .header-badge .time {
            font-size: 13px;
            color: #f1f5f9;
            margin-top: 2px;
            font-weight: 600;
        }

        /* ALERT */
        .alert {
            padding: 14px 20px;
            background-color: #ecfdf5;
            border-left: 4px solid var(--accent-green);
            color: #065f46;
            border-radius: 10px;
            margin-bottom: 24px;
            font-weight: 600;
            font-size: 14px;
        }

        /* STAT CARDS */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .stat-card .label {
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 8px;
        }

        .stat-card .value {
            font-size: 26px;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 4px;
        }

        .stat-card .desc {
            color: var(--text-muted);
            font-size: 12px;
        }

        /* MAIN DISPENSER PANEL */
        .dispenser-panel {
            display: grid;
            grid-template-columns: 1.8fr 1.2fr;
            gap: 24px;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
        }

        .panel-left h2 {
            font-size: 14px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .panel-left .device-subtitle {
            font-size: 20px;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 20px;
        }

        .reading-container {
            margin-bottom: 18px;
        }

        .reading {
            font-size: 52px;
            font-weight: 800;
            color: var(--primary);
            display: inline-block;
            line-height: 1;
        }

        .reading span {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-muted);
            margin-left: 10px;
        }

        .progress-bar-container {
            width: 100%;
            height: 22px;
            background-color: #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 20px;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .progress-bar {
            height: 100%;
            border-radius: 12px;
            transition: width 0.4s ease, background-color 0.4s ease;
        }

        .bg-emerald { background: linear-gradient(90deg, #10b981, #059669); }
        .bg-cyan { background: linear-gradient(90deg, #06b6d4, #0891b2); }
        .bg-amber { background: linear-gradient(90deg, #f59e0b, #d97706); }
        .bg-rose { background: linear-gradient(90deg, #f43f5e, #e11d48); }
        .bg-blue { background: linear-gradient(90deg, #0284c7, #2563eb); }
        .bg-gray { background: #94a3b8; }

        .badges-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 22px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .badge-emerald { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-cyan { background: #cffafe; color: #0e7490; border: 1px solid #a5f3fc; }
        .badge-amber { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .badge-rose { background: #ffe4e6; color: #be123c; border: 1px solid #fecdd3; }
        .badge-blue { background: #dbeafe; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .badge-orange { background: #ffedd5; color: #c2410c; border: 1px solid #fed7aa; }
        .badge-gray { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }

        .panel-meta {
            font-size: 13px;
            line-height: 1.8;
            color: var(--text-muted);
            border-top: 1px solid #f1f5f9;
            padding-top: 16px;
        }

        .panel-meta strong {
            color: var(--text-main);
        }

        /* RIGHT PANEL: ACTIONS & CONTROLS */
        .panel-right {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .panel-right h3 {
            font-size: 13px;
            color: var(--text-muted);
            text-transform: uppercase;
            font-weight: 800;
            letter-spacing: 0.6px;
            margin-bottom: 14px;
        }

        .info-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 18px;
        }

        .info-card p {
            font-size: 13px;
            color: var(--text-main);
            margin-bottom: 6px;
        }

        .info-card p:last-child {
            margin-bottom: 0;
            color: var(--text-muted);
            font-size: 12px;
        }

        .btn-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 13px 20px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); }

        .btn-warning { background: #d97706; color: white; }
        .btn-warning:hover { background: #b45309; }

        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; }

        .btn-secondary { background: #e2e8f0; color: #334155; }
        .btn-secondary:hover { background: #cbd5e1; }

        /* TABLE SECTION */
        .section-box {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 26px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
        }

        .section-box h3 {
            font-size: 15px;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 18px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th {
            background: #f8fafc;
            color: var(--text-muted);
            text-align: left;
            padding: 12px 16px;
            font-weight: 700;
            border-bottom: 1px solid var(--border);
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
        }

        td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            color: var(--text-main);
            vertical-align: middle;
        }

        tr:hover { background-color: #f8fafc; }

        @media (max-width: 900px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .dispenser-panel { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <!-- HEADER DASHBOARD -->
    <header>
        <div class="header-title">
            <h1>Smart Water Bottle Refill Dispenser</h1>
            <p>Sistem Pengisian Air Otomatis Berbasis IoT & Logika Server — Projek Akhir IoT</p>
        </div>
        <div class="header-badge">
            <div class="tag">● DISPENSER AKTIF</div>
            <div class="time">{{ now()->timezone('Asia/Jakarta')->translatedFormat('d F Y • H:i') }} WIB</div>
        </div>
    </header>

    @if(session('success'))
        <div class="alert">
            ✓ {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="alert" style="background: #fef2f2; border-color: #ef4444; color: #991b1b;">
            ⚠ {{ session('error') }}
        </div>
    @endif

    <!-- 4 KARTU STATISTIK RINGKAS -->
    <section class="stats-grid">
        <div class="stat-card">
            <div class="label">STATUS OPERASIONAL</div>
            <div class="value" id="statDispenserStatus" style="font-size: 20px;">
                {{ $statusInfo['badge'] }}
            </div>
            <div class="desc" id="statDispenserDesc">{{ $statusInfo['description'] }}</div>
        </div>

        <div class="stat-card">
            <div class="label">SISA AIR BOTOL</div>
            <div class="value" id="statWaterPercent">
                {{ $latest && $latest->water_percent !== null ? number_format($latest->water_percent, 0) : 0 }}%
            </div>
            <div class="desc" id="statWaterMl">
                ±{{ $latest && $latest->water_weight_g !== null ? number_format(max(0, $latest->water_weight_g), 0) : 0 }} mL dari 700 mL
            </div>
        </div>

        <div class="stat-card">
            <div class="label">REFILL HARI INI</div>
            <div class="value" id="statRefillToday" style="color: var(--accent-blue);">
                {{ $refillCountToday }} kali
            </div>
            <div class="desc">Siklus pengisian berhasil hari ini</div>
        </div>

        <div class="stat-card">
            <div class="label">TOTAL REFILL KUMULATIF</div>
            <div class="value" id="statRefillTotal">
                {{ $refillCountTotal }} kali
            </div>
            <div class="desc">Total pengisian botol sukses tercatat</div>
        </div>
    </section>

    <!-- PANEL DISPENSER UTAMA -->
    <section class="dispenser-panel">
        <!-- SISI KIRI: PEMANTAUAN VOLUME & BOTOL -->
        <div class="panel-left">
            <h2>UNIT DISPENSER TUNGGAL</h2>
            <div class="device-subtitle">Stasiun Refill Galon & Tatakan Timbangan</div>

            @php
                $percent = $latest && $latest->water_percent !== null ? (float) $latest->water_percent : 0.0;
                $waterWeight = $latest && $latest->water_weight_g !== null ? max(0, (float) $latest->water_weight_g) : 0.0;
                $capacity = 700.0;
                
                $barColor = match($statusInfo['color']) {
                    'emerald' => 'bg-emerald',
                    'cyan' => 'bg-cyan',
                    'amber' => 'bg-amber',
                    'rose' => 'bg-rose',
                    'blue' => 'bg-blue',
                    default => 'bg-gray'
                };

                $badgeClass = match($statusInfo['color']) {
                    'emerald' => 'badge-emerald',
                    'cyan' => 'badge-cyan',
                    'amber' => 'badge-amber',
                    'rose' => 'badge-rose',
                    'blue' => 'badge-blue',
                    'orange' => 'badge-orange',
                    'red' => 'badge-red',
                    default => 'badge-gray'
                };
            @endphp

            <div class="reading-container">
                <div class="reading" id="liveReading">
                    {{ number_format($percent, 0) }}% 
                    <span>| ±{{ number_format($waterWeight, 0) }} mL air (Kapasitas: {{ number_format($capacity, 0) }} mL)</span>
                </div>
            </div>

            <div class="progress-bar-container">
                <div class="progress-bar {{ $barColor }}" id="liveProgressBar" style="width: {{ min(100, max(0, $percent)) }}%;"></div>
            </div>

            <div class="badges-row">
                <span class="status-badge {{ $badgeClass }}" id="liveStatusBadge">{{ $statusInfo['badge'] }}</span>
                
                <span class="status-badge {{ $latest && $latest->pump_on ? 'badge-blue' : 'badge-gray' }}" id="livePumpBadge">
                    Pompa: {{ $latest && $latest->pump_on ? 'MENYALA (ON)' : 'MATI (OFF)' }}
                </span>

                <span class="status-badge {{ $latest && $latest->bottle_present ? 'badge-emerald' : 'badge-gray' }}" id="liveBottleBadge">
                    Botol: {{ $latest && $latest->bottle_present ? 'ADA DI TATAKAN' : 'TANPA BOTOL' }}
                </span>
            </div>

            <div class="panel-meta">
                <p><strong>Logika Pengisian:</strong> Refill otomatis aktif saat botol berada di dudukan dengan sisa air &le; 30%, dan berhenti di 80% (579g).</p>
                <p><strong>Kondisi Sensor:</strong> <span id="liveMetaUkur">
                    {{ $latest && $latest->received_at ? 'Terakhir ' . $latest->received_at->timezone('Asia/Jakarta')->format('H:i:s') . ' WIB' : 'Belum ada data sensor masuk.' }}
                    @if($latest)
                        (Berat Total: {{ number_format($latest->total_weight_g, 1) }} g | Raw: {{ $latest->raw_weight }})
                    @endif
                </span></p>
            </div>
        </div>

        <!-- SISI KANAN: KONTROL & PENGUJIAN ALAT -->
        <div class="panel-right">
            <div>
                <h3>PENGUJIAN & KONTROL ALAT</h3>
                
                <div class="info-card">
                    <p><strong>Uji Cepat Relay Pompa:</strong></p>
                    <p>Tekan tombol uji untuk memastikan relay fisik (Pin 25 & 27) berbunyi klik dan pompa menyala tanpa harus menunggu air turun.</p>
                </div>
            </div>

            <div class="btn-group">
                <!-- Tombol Uji Nyala Pompa -->
                <form action="{{ route('device.test-pump') }}" method="POST">
                    @csrf
                    <input type="hidden" name="rack" value="rack01">
                    <button type="submit" class="btn btn-primary">
                        ⚡ Test Nyalakan Pompa (4 Detik)
                    </button>
                </form>

                <!-- Tombol Tare / Nolkan Timbangan -->
                <form action="{{ route('device.tare') }}" method="POST">
                    @csrf
                    <input type="hidden" name="rack" value="rack01">
                    <button type="submit" class="btn btn-secondary">
                        ⚖ Tare / Nolkan Timbangan Sekarang
                    </button>
                </form>

                <!-- Tombol Stop Darurat jika pompa menyala -->
                <form action="{{ route('device.force-stop') }}" method="POST">
                    @csrf
                    <input type="hidden" name="rack" value="rack01">
                    <button type="submit" class="btn btn-danger">
                        🛑 Stop / Matikan Pompa Segera
                    </button>
                </form>
            </div>
        </div>
    </section>

    <!-- TABEL RIWAYAT SIKLUS PENGISIAN TERAKHIR -->
    <section class="section-box">
        <h3>RIWAYAT PENGISIAN AIR DISPENSER TERBARU</h3>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Waktu (WIB)</th>
                        <th>Siklus Refill</th>
                        <th>Berat Awal</th>
                        <th>Berat Akhir</th>
                        <th>Air Terisi</th>
                        <th>Target</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="liveRefillTable">
                    @forelse($recentRefills as $refill)
                        <tr>
                            <td>{{ $refill->created_at ? $refill->created_at->timezone('Asia/Jakarta')->format('d/m/Y H:i:s') : '—' }}</td>
                            <td><strong>REF-{{ substr($refill->refill_id, 0, 8) }}</strong></td>
                            <td>{{ number_format($refill->start_total_weight_g, 1) }} g</td>
                            <td>{{ $refill->end_total_weight_g ? number_format($refill->end_total_weight_g, 1) . ' g' : '—' }}</td>
                            <td>
                                @if($refill->added_water_g > 0)
                                    <strong style="color: var(--accent-green);">+{{ number_format($refill->added_water_g, 0) }} mL</strong>
                                @else
                                    <span style="color: var(--text-muted);">—</span>
                                @endif
                            </td>
                            <td>{{ number_format($refill->target_percent, 0) }}%</td>
                            <td>
                                @if($refill->status === 'completed')
                                    <span class="status-badge badge-emerald">Sukses Tercapai</span>
                                @elseif($refill->status === 'filling')
                                    <span class="status-badge badge-blue">Sedang Mengisi</span>
                                @elseif($refill->status === 'cancelled')
                                    <span class="status-badge badge-orange">{{ $refill->finish_reason ?? 'Dibatalkan' }}</span>
                                @else
                                    <span class="status-badge badge-gray">{{ $refill->finish_reason ?? 'Gagal' }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 24px;">
                                Belum ada riwayat pengisian air yang tercatat.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

<script>
    // Pembaruan data real-time secara halus (AJAX Polling tiap 2.5 detik)
    async function updateDashboardData() {
        try {
            const res = await fetch('{{ route("api.status", ["rack" => "rack01"]) }}');
            if (!res.ok) return;
            const data = await res.json();

            if (data.latest) {
                const percent = Math.min(100, Math.max(0, Math.round(data.latest.water_percent || 0)));
                const ml = Math.max(0, Math.round(data.latest.water_weight_g || 0));
                const capacity = 700;

                // 1. Text persentase & ml
                const readingElem = document.getElementById('liveReading');
                if (readingElem) {
                    readingElem.innerHTML = `${percent}% <span>| ±${ml} mL air (Kapasitas: ${capacity} mL)</span>`;
                }

                // 2. Stat cards
                const statPct = document.getElementById('statWaterPercent');
                if (statPct) statPct.innerText = `${percent}%`;
                const statMl = document.getElementById('statWaterMl');
                if (statMl) statMl.innerText = `±${ml} mL dari ${capacity} mL`;

                // 3. Progress bar width
                const progressBar = document.getElementById('liveProgressBar');
                if (progressBar) {
                    progressBar.style.width = percent + '%';
                }

                // 4. Badges
                const statusBadge = document.getElementById('liveStatusBadge');
                if (statusBadge && data.statusInfo) {
                    statusBadge.innerText = data.statusInfo.badge;
                }

                const statStatus = document.getElementById('statDispenserStatus');
                if (statStatus && data.statusInfo) {
                    statStatus.innerText = data.statusInfo.badge;
                }

                const statDesc = document.getElementById('statDispenserDesc');
                if (statDesc && data.statusInfo) {
                    statDesc.innerText = data.statusInfo.description;
                }

                const pumpBadge = document.getElementById('livePumpBadge');
                if (pumpBadge) {
                    pumpBadge.innerText = data.latest.pump_on ? 'Pompa: MENYALA (ON)' : 'Pompa: MATI (OFF)';
                    pumpBadge.className = 'status-badge ' + (data.latest.pump_on ? 'badge-blue' : 'badge-gray');
                }

                const bottleBadge = document.getElementById('liveBottleBadge');
                if (bottleBadge) {
                    bottleBadge.innerText = data.latest.bottle_present ? 'Botol: ADA DI TATAKAN' : 'Botol: TANPA BOTOL';
                    bottleBadge.className = 'status-badge ' + (data.latest.bottle_present ? 'badge-emerald' : 'badge-gray');
                }

                const metaUkur = document.getElementById('liveMetaUkur');
                if (metaUkur && data.latest.received_at) {
                    const timeStr = new Date(data.latest.received_at).toLocaleTimeString('id-ID');
                    metaUkur.innerText = `Terakhir ${timeStr} WIB (Berat Total: ${Number(data.latest.total_weight_g).toFixed(1)} g | Raw: ${data.latest.raw_weight})`;
                }
            }

            if (data.refillCountToday !== undefined) {
                const elToday = document.getElementById('statRefillToday');
                if (elToday) elToday.innerText = data.refillCountToday + ' kali';
            }
            if (data.refillCountTotal !== undefined) {
                const elTotal = document.getElementById('statRefillTotal');
                if (elTotal) elTotal.innerText = data.refillCountTotal + ' kali';
            }
        } catch (e) {
            // Silently ignore network hiccup
        }
    }

    // Jalankan pembaruan otomatis tiap 2.5 detik
    setInterval(updateDashboardData, 2500);
</script>

</body>
</html>
