<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Smart Refill — Monitor MQTT</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #f3f7fa;
            color: #203547;
            font-family: Arial, sans-serif;
        }

        main {
            max-width: 1100px;
            margin: 40px auto;
            padding: 0 20px;
        }

        header {
            background: #143b55;
            color: white;
            padding: 28px;
            border-radius: 16px;
            margin-bottom: 22px;
        }

        h1 { margin: 0 0 10px; font-size: 26px; }
        h2 { margin-top: 0; font-size: 20px; }
        p { line-height: 1.6; }

        header p { margin: 0; color: #d7e7f0; }

        .cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 22px;
        }

        .card, .panel {
            background: white;
            padding: 24px;
            border: 1px solid #dce6ed;
            border-radius: 14px;
        }

        .label { color: #627789; font-size: 14px; }

        .value {
            font-size: 26px;
            font-weight: bold;
            margin-top: 12px;
        }

        .badge {
            display: inline-block;
            padding: 7px 12px;
            background: #fff0d9;
            color: #895310;
            border-radius: 20px;
            font-size: 13px;
            font-weight: bold;
        }

        .panel { margin-bottom: 22px; }
        .latest { font-size: 20px; overflow-wrap: anywhere; }
        .muted { color: #627789; font-size: 14px; }
        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        th, td {
            text-align: left;
            padding: 14px 12px;
            border-bottom: 1px solid #e3ebf0;
            vertical-align: top;
        }

        th { background: #eaf2f7; }
        td { overflow-wrap: anywhere; }

        @media (max-width: 700px) {
            .cards { grid-template-columns: 1fr; }
            main { margin-top: 20px; }
        }
    </style>
</head>

<body>
<main>
    @php
        $latest = $messages->first();
        $latestPayload = $latest
            ? json_decode($latest->payload, true)
            : [];
    @endphp

    <header>
        <h1>Smart Water Bottle Refill Reminder</h1>
        <p>Monitor pesan MQTT — pengujian koneksi backend</p>
    </header>

    <section class="cards">
        <div class="card">
            <div class="label">PERANGKAT UJI</div>
            <div class="value">rack01</div>
        </div>

        <div class="card">
            <div class="label">PESAN TERSIMPAN</div>
            <div class="value">{{ $totalMessages }}</div>
        </div>

        <div class="card">
            <div class="label">PEMBARUAN HALAMAN</div>
            <div class="value">5 detik</div>
        </div>
    </section>

    <section class="panel">
        <h2>Pesan terakhir</h2>

        @if ($latest)
            <span class="badge">
                {{ $latest->simulated ? 'SIMULASI' : 'NON-SIMULASI' }}
            </span>

            <p class="latest">
                {{ $latestPayload['message'] ?? 'Pesan tanpa teks' }}
            </p>

            <p class="muted">
                Diterima:
                {{ $latest->created_at->timezone('Asia/Jakarta')->format('d-m-Y H:i:s') }}
                WIB
                <br>
                Topic: {{ $latest->topic }}
            </p>
        @else
            <p>Belum ada pesan tersimpan.</p>
        @endif
    </section>

    <section class="panel">
        <h2>Riwayat 20 pesan terbaru</h2>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Waktu diterima (WIB)</th>
                    <th>Perangkat</th>
                    <th>Pesan</th>
                    <th>Jenis data</th>
                </tr>
                </thead>

                <tbody>
                @forelse ($messages as $item)
                    @php
                        $payload = json_decode($item->payload, true);
                    @endphp

                    <tr>
                        <td>{{ $item->id }}</td>
                        <td>
                            {{ $item->created_at->timezone('Asia/Jakarta')->format('d-m-Y H:i:s') }}
                        </td>
                        <td>{{ $item->device_id }}</td>
                        <td>{{ $payload['message'] ?? '—' }}</td>
                        <td>
                            {{ $item->simulated ? 'Simulasi' : 'Non-simulasi' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">Belum ada pesan.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <p class="muted">
        Jumlah pesan merupakan catatan pengujian komunikasi.
        Jumlah refill akan dihitung dari siklus pengisian berhasil.
    </p>
</main>
</body>
</html>