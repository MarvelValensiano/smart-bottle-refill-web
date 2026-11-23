(function () {
    const menu = document.getElementById('mobileMenu');
    const sidebar = document.getElementById('sidebar');
    if (menu && sidebar) {
        menu.addEventListener('click', () => sidebar.classList.toggle('open'));
    }

    const initPolling = () => {
        const config = window.SmartFillConfig || { statusUrl: '/api/status', pollMs: 2000 };
        const statusUrl = config.statusUrl || '/api/status';
        const pollInterval = config.pollMs || 2000;

        const setText = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        };

        const escapeHtml = (str) => {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        };

        const refresh = async () => {
            try {
                const response = await fetch(statusUrl, {
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store'
                });

                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                const d = await response.json();

                const safePercent = (d.percent !== null && d.percent !== undefined) ? Math.max(0, Math.min(100, Number(d.percent))) : null;
                const safeVolume = (d.volume_ml !== null && d.volume_ml !== undefined) ? Math.max(0, Math.round(Number(d.volume_ml))) : null;

                setText('waterPercent', safePercent !== null ? Math.round(safePercent) : '--');
                setText('waterVolume', safeVolume !== null ? safeVolume.toLocaleString('id-ID') : '--');
                setText('totalWeight', (d.total_weight_g !== null && d.total_weight_g !== undefined) ? Math.round(Number(d.total_weight_g)) + ' g' : '--');
                setText('emptyWeight', (d.bottle_empty_weight_g !== null && d.bottle_empty_weight_g !== undefined) ? Math.round(Number(d.bottle_empty_weight_g)) + ' g' : '19 g');
                setText('waterStatus', d.water_status || 'BELUM ADA DATA');
                setText('pumpStatus', d.pump_on ? 'ON' : 'OFF');
                setText('bottleStatus', d.bottle_present === null ? 'Belum diketahui' : (d.bottle_present ? 'TERPASANG' : 'TIDAK ADA'));
                setText('todayRefill', d.refill_today ?? 0);
                setText('totalRefill', d.refill_total ?? 0);

                const alertEl = document.getElementById('alertCount');
                if (alertEl) {
                    alertEl.textContent = d.unresolved_alerts ?? 0;
                }

                setText('connectionText', d.online ? 'ONLINE' : 'OFFLINE');
                setText('lastSeen', d.last_seen_at ? new Date(d.last_seen_at).toLocaleTimeString('id-ID') : 'Belum ada data');
                setText('lastUpdated', 'Pembaruan terakhir: ' + (d.received_at ? new Date(d.received_at).toLocaleTimeString('id-ID') : new Date().toLocaleTimeString('id-ID')));

                const gauge = document.getElementById('waterGauge');
                const percentVal = safePercent !== null ? safePercent : 0;
                if (gauge) {
                    gauge.style.setProperty('--value', percentVal + '%');
                }

                const bottleWater = document.getElementById('bottleWater');
                if (bottleWater) {
                    bottleWater.style.height = percentVal + '%';
                }

                const dot = document.getElementById('connectionDot');
                if (dot) {
                    dot.classList.toggle('connected', !!d.online);
                }

                const conn = document.getElementById('connectionText');
                if (conn) {
                    conn.className = 'stat-value status-word ' + (d.online ? 'text-green' : 'text-red');
                }

                const rack = document.getElementById('rackStatus');
                if (rack) {
                    rack.className = 'status-chip ' + (d.online ? 'chip-green' : 'chip-red');
                    rack.innerHTML = '<i></i>' + (d.online ? 'Sistem normal' : 'Perangkat offline');
                }

                const state = document.getElementById('refillState');
                if (state) {
                    const active = ['pending', 'filling', 'paused'].includes(d.refill_status);
                    state.className = 'status-chip ' + (active ? 'chip-blue' : 'chip-gray');
                    state.innerHTML = '<i></i>' + (active ? String(d.refill_status).toUpperCase() : 'IDLE');
                }

                const progress = (d.progress === null || d.progress === undefined) ? null : Math.max(0, Math.min(100, Number(d.progress)));
                setText('progressPercent', progress === null ? '—' : Math.round(progress) + '%');

                const fill = document.getElementById('progressFill');
                if (fill) {
                    fill.style.width = (progress || 0) + '%';
                }

                setText('progressCurrent', safePercent === null ? '—' : Math.round(safePercent) + '%');
                setText('progressTarget', d.target_percent === null ? '—' : Math.round(d.target_percent) + '%');

                // Update Pengguna dan Botol Aktif
                if (d.session_athlete_name) {
                    setText('sessionAthlete', d.session_athlete_name);
                    setText('historyAthleteName', d.session_athlete_name);
                    setText('alertsAthleteName', d.session_athlete_name);
                }
                if (d.session_bottle_code) {
                    setText('sessionBottle', d.session_bottle_code);
                }

                // Update Riwayat Refill Terbaru Secara Otomatis
                const refillsBody = document.getElementById('recentRefillsBody');
                if (refillsBody && Array.isArray(d.recent_refills)) {
                    if (d.recent_refills.length === 0) {
                        refillsBody.innerHTML = '<tr><td colspan="5" class="empty-state">Belum ada riwayat refill untuk atlet ini.</td></tr>';
                    } else {
                        refillsBody.innerHTML = d.recent_refills.map(r => {
                            let pillClass = 'result-pending';
                            if (r.status_raw === 'completed') pillClass = 'result-success';
                            else if (r.status_raw === 'failed' || r.status_raw === 'cancelled') pillClass = 'result-failed';

                            return `<tr>
                                <td>
                                    <b>${escapeHtml(r.started_time)}</b>
                                    <small>${escapeHtml(r.started_date)}</small>
                                </td>
                                <td>${escapeHtml(r.athlete_name)}</td>
                                <td>${escapeHtml(r.bottle_code)}</td>
                                <td>
                                    <span class="result-pill ${pillClass}">
                                        ${escapeHtml(r.status)}
                                    </span>
                                </td>
                                <td>${escapeHtml(r.duration)}</td>
                            </tr>`;
                        }).join('');
                    }
                }

                // Update Aktivitas Gangguan Secara Otomatis
                const eventsList = document.getElementById('recentEventsList');
                if (eventsList && Array.isArray(d.recent_events)) {
                    if (d.recent_events.length === 0) {
                        eventsList.innerHTML = `<div class="empty-alert">
                            <span>✓</span>
                            <b>Semua terkendali</b>
                            <small>Tidak ada gangguan pada sesi atlet ini.</small>
                        </div>`;
                    } else {
                        eventsList.innerHTML = d.recent_events.map(e => {
                            let markClass = 'mark-blue';
                            if (e.severity_raw === 'error') markClass = 'mark-red';
                            else if (e.severity_raw === 'warning') markClass = 'mark-amber';

                            return `<div class="alert-item">
                                <span class="alert-mark ${markClass}">!</span>
                                <div>
                                    <b>${escapeHtml(e.type)}</b>
                                    <p>${escapeHtml(e.message)}</p>
                                    <small>${escapeHtml(e.time)}</small>
                                </div>
                                <span class="severity">${escapeHtml(e.severity)}</span>
                            </div>`;
                        }).join('');
                    }
                }
            } catch (err) {
                const conn = document.getElementById('connectionText');
                if (conn) {
                    conn.textContent = 'OFFLINE';
                    conn.className = 'stat-value status-word text-red';
                }
            }
        };

        refresh();
        window.setInterval(refresh, pollInterval);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPolling);
    } else {
        initPolling();
    }
})();
