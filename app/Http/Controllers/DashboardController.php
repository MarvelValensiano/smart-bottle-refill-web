<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\Bottle;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceEvent;
use App\Models\DeviceSetting;
use App\Models\Refill;
use App\Models\Telemetry;
use App\Models\UsageSession;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DashboardController extends Controller
{
    private const RACK = 'rack01';

    public function index(): View
    {
        return view('smartfill.dashboard', $this->dashboardData());
    }

    public function apiStatus(): JsonResponse
    {
        return response()->json($this->statusPayload());
    }

    public function history(Request $request): View
    {
        $device = Device::where('code', self::RACK)->first();
        $unresolved = $device ? DeviceEvent::where('device_id', $device->id)->whereNull('resolved_at')->count() : 0;

        $query = Refill::with(['athlete', 'bottle'])
            ->whereHas('device', fn ($q) => $q->where('code', self::RACK))
            ->latest('started_at');

        if ($request->filled('result')) {
            $query->where('status', $request->string('result')->toString());
        }

        return view('smartfill.history', [
            'refills' => $query->paginate(15)->withQueryString(),
            'unresolved' => $unresolved,
        ]);
    }

    public function alerts(): View
    {
        $device = Device::where('code', self::RACK)->first();
        $unresolved = $device ? DeviceEvent::where('device_id', $device->id)->whereNull('resolved_at')->count() : 0;

        $events = DeviceEvent::when($device, fn ($q) => $q->where('device_id', $device->id))
            ->latest('occurred_at')
            ->paginate(15);

        return view('smartfill.alerts', compact('events', 'unresolved'));
    }

    public function athletes(): View
    {
        $device = Device::where('code', self::RACK)->first();
        $unresolved = $device ? DeviceEvent::where('device_id', $device->id)->whereNull('resolved_at')->count() : 0;
        $activeRefill = $device ? Refill::where('device_id', $device->id)->whereIn('status', ['filling', 'paused'])->latest('id')->first() : null;
        $activeSession = $device ? UsageSession::with(['athlete', 'bottle'])->where('device_id', $device->id)->where('status', 'active')->latest('id')->first() : null;

        return view('smartfill.athletes', [
            'athletes' => Athlete::with('bottles')->orderBy('name')->paginate(15),
            'unresolved' => $unresolved,
            'activeRefill' => $activeRefill,
            'activeSession' => $activeSession,
        ]);
    }

    public function storeAthlete(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:50|unique:athletes,code',
            'name' => 'required|string|max:100',
            'bottle_code' => 'required|string|max:50|unique:bottles,code',
            'bottle_name' => 'required|string|max:100',
            'empty_weight_g' => 'required|numeric|min:0|max:10000',
            'water_capacity_g' => 'required|numeric|min:1|max:10000',
        ]);

        DB::transaction(function () use ($data) {
            $athlete = Athlete::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'is_active' => true,
            ]);

            Bottle::create([
                'code' => $data['bottle_code'],
                'name' => $data['bottle_name'],
                'athlete_id' => $athlete->id,
                'empty_weight_g' => $data['empty_weight_g'],
                'water_capacity_g' => $data['water_capacity_g'],
            ]);
        });

        return back()->with('success', 'Data atlet dan profil botol berhasil ditambahkan.');
    }

    public function updateAthlete(Request $request, Athlete $athlete): RedirectResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:50|unique:athletes,code,' . $athlete->id,
            'name' => 'required|string|max:100',
            'is_active' => 'nullable',
            'bottle_id' => 'nullable|exists:bottles,id',
            'bottle_code' => 'nullable|string|max:50',
            'bottle_name' => 'nullable|string|max:100',
            'empty_weight_g' => 'nullable|numeric|min:0|max:10000',
            'water_capacity_g' => 'nullable|numeric|min:1|max:10000',
        ]);

        DB::transaction(function () use ($athlete, $data) {
            $athlete->update([
                'code' => $data['code'],
                'name' => $data['name'],
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
            ]);

            if (!empty($data['bottle_id'])) {
                $bottle = Bottle::where('id', $data['bottle_id'])
                    ->where('athlete_id', $athlete->id)
                    ->first();

                if ($bottle) {
                    $bottle->update([
                        'code' => $data['bottle_code'] ?? $bottle->code,
                        'name' => $data['bottle_name'] ?? $bottle->name,
                        'empty_weight_g' => $data['empty_weight_g'] ?? $bottle->empty_weight_g,
                        'water_capacity_g' => $data['water_capacity_g'] ?? $bottle->water_capacity_g,
                    ]);

                    UsageSession::where('bottle_id', $bottle->id)
                        ->where('status', 'active')
                        ->update([
                            'bottle_empty_weight_g' => $bottle->empty_weight_g,
                            'water_capacity_g' => $bottle->water_capacity_g,
                        ]);
                }
            }
        });

        return back()->with('success', 'Data atlet "' . $athlete->name . '" berhasil diperbarui.');
    }

    public function switchSession(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'athlete_id' => 'required|exists:athletes,id',
            'bottle_id' => 'required|exists:bottles,id',
        ]);

        $device = Device::where('code', self::RACK)->firstOrFail();
        $bottle = Bottle::with('athlete')->findOrFail($data['bottle_id']);

        abort_unless((int) $bottle->athlete_id === (int) $data['athlete_id'], 422, 'Botol tidak sesuai dengan atlet.');

        DB::transaction(function () use ($device, $bottle, $data) {
            // Jika ada pengisian (refill) yang sedang aktif atau dijeda saat pergantian sesi:
            // Batalkan status refill dan hentikan pompa seketika demi keamanan
            $activeRefill = Refill::where('device_id', $device->id)
                ->whereIn('status', ['filling', 'paused'])
                ->latest('id')
                ->first();

            if ($activeRefill) {
                $activeRefill->update([
                    'status' => 'cancelled',
                    'finish_reason' => 'SESSION_SWITCHED',
                    'finished_at' => now(),
                ]);

                // Kirim perintah matikan pompa ke ESP32
                $seq = DeviceCommand::where('device_id', $device->id)->max('command_sequence') ?? 0;
                DeviceCommand::create([
                    'command_id' => (string) Str::uuid(),
                    'device_id' => $device->id,
                    'refill_id' => $activeRefill->id,
                    'target_boot_id' => (string) Str::uuid(),
                    'command_sequence' => $seq + 1,
                    'type' => 'set_pump',
                    'payload' => ['pump' => false],
                    'status' => 'pending',
                    'lease_ms' => 0,
                    'expires_at' => now()->addSeconds(30),
                ]);
            }

            UsageSession::where('device_id', $device->id)
                ->where('status', 'active')
                ->update(['status' => 'completed', 'ended_at' => now()]);

            $code = 'SES-' . now()->format('ymdHis');
            UsageSession::create([
                'session_code' => $code,
                'device_id' => $device->id,
                'athlete_id' => $data['athlete_id'],
                'bottle_id' => $bottle->id,
                'status' => 'active',
                'refill_permitted' => true,
                'is_locked' => false,
                'bottle_empty_weight_g' => $bottle->empty_weight_g,
                'water_capacity_g' => $bottle->water_capacity_g,
                'started_at' => now(),
            ]);

            $device->setting()->update(['bottle_id' => $bottle->id]);
        });

        return back()->with('success', 'Sesi atlet berhasil diaktifkan untuk ' . $bottle->athlete->name . '.');
    }

    public function acknowledgeAlarm(): RedirectResponse
    {
        $device = Device::where('code', self::RACK)->firstOrFail();
        $session = UsageSession::where('device_id', $device->id)->where('status', 'active')->first();

        if ($session) {
            $session->update(['is_locked' => false, 'lock_reason' => null]);
        }

        DeviceEvent::where('device_id', $device->id)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);

        return back()->with('success', 'Alarm diakui dan ditandai selesai. Pastikan kondisi fisik rak aman sebelum sistem digunakan kembali.');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'start_percent' => 'required|numeric|min:0|max:100',
            'stop_percent' => 'required|numeric|min:0|max:100|gt:start_percent',
            'zero_tolerance_g' => 'required|numeric|min:0|max:100',
            'bottle_present_g' => 'required|numeric|min:0|max:1000',
            'bottle_removed_g' => 'required|numeric|min:0|max:1000|lt:bottle_present_g',
            'no_gain_timeout_ms' => 'required|integer|min:1000|max:120000',
            'min_gain_g' => 'required|numeric|min:0|max:1000',
            'calibration_factor' => 'nullable|numeric|min:1',
            'stable_duration_ms' => 'nullable|integer|min:500|max:10000',
            'stable_range_g' => 'nullable|numeric|min:0.5|max:50',
        ]);

        $device = Device::where('code', self::RACK)->firstOrFail();
        $device->setting()->update($data);

        return back()->with('success', 'Parameter monitoring dan kalibrasi berhasil disimpan.');
    }

    public function calibrateScale(Request $request): RedirectResponse
    {
        $request->validate([
            'known_weight_g' => 'required|numeric|min:1|max:5000',
        ]);

        $device = Device::with('setting')->where('code', self::RACK)->firstOrFail();
        $latest = Telemetry::where('device_id', $device->id)->latest('id')->first();

        if (!$latest || $latest->raw_weight === null) {
            return back()->with('error', 'Belum ada pembacaan sensor terbaru untuk kalibrasi.');
        }

        $tare = $device->setting->tare_offset ?? 0;
        $diff = $latest->raw_weight - $tare;

        if (abs($diff) < 100) {
            return back()->with('error', 'Beban sensor terlalu kecil. Letakkan beban uji (misal botol terisi) di atas timbangan sebelum kalibrasi.');
        }

        $newFactor = $diff / (float) $request->input('known_weight_g');

        $device->setting()->update([
            'calibration_factor' => $newFactor,
        ]);

        return back()->with('success', "Kalibrasi berhasil! Faktor baru: " . number_format($newFactor, 4) . " (berdasarkan beban asli {$request->input('known_weight_g')} g).");
    }

    public function updateBottleWeight(Request $request): RedirectResponse
    {
        $request->validate([
            'empty_weight_g' => 'required|numeric|min:1|max:2000',
            'water_capacity_g' => 'required|numeric|min:50|max:5000',
        ]);

        $device = Device::with('setting.bottle')->where('code', self::RACK)->firstOrFail();
        $bottle = $device->setting?->bottle;

        if ($bottle) {
            $bottle->update([
                'empty_weight_g' => $request->input('empty_weight_g'),
                'water_capacity_g' => $request->input('water_capacity_g'),
            ]);
        }

        UsageSession::where('device_id', $device->id)
            ->where('status', 'active')
            ->update([
                'bottle_empty_weight_g' => $request->input('empty_weight_g'),
                'water_capacity_g' => $request->input('water_capacity_g'),
            ]);

        return back()->with('success', "Spesifikasi botol berhasil disesuaikan: Kosong {$request->input('empty_weight_g')} g, Kapasitas {$request->input('water_capacity_g')} mL.");
    }

    public function forceStopPump(Request $request): RedirectResponse
    {
        $rackCode = $request->input('rack', self::RACK);
        $device = Device::where('code', $rackCode)->firstOrFail();

        $activeRefill = Refill::where('device_id', $device->id)
            ->whereIn('status', ['filling', 'paused'])
            ->first();

        if ($activeRefill) {
            $activeRefill->update([
                'status' => 'cancelled',
                'finish_reason' => 'MANUAL_STOP',
                'finished_at' => now(),
            ]);
        }

        $seq = DeviceCommand::where('device_id', $device->id)->max('command_sequence') ?? 0;
        DeviceCommand::create([
            'command_id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'target_boot_id' => (string) Str::uuid(),
            'command_sequence' => $seq + 1,
            'type' => 'set_pump',
            'payload' => ['pump' => false],
            'status' => 'pending',
            'lease_ms' => 0,
            'expires_at' => now()->addSeconds(30),
        ]);

        return back()->with('success', "Perintah stop pompa berhasil dikirim.");
    }

    public function testPump(Request $request): RedirectResponse
    {
        $rackCode = $request->input('rack', self::RACK);
        $device = Device::where('code', $rackCode)->firstOrFail();
        $seq = DeviceCommand::where('device_id', $device->id)->max('command_sequence') ?? 0;

        DeviceCommand::create([
            'command_id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'target_boot_id' => (string) Str::uuid(),
            'command_sequence' => $seq + 1,
            'type' => 'set_pump',
            'payload' => ['pump' => true, 'lease_ms' => 4000],
            'status' => 'pending',
            'lease_ms' => 4000,
            'expires_at' => now()->addSeconds(30),
        ]);

        return back()->with('success', "Perintah uji nyala pompa selama 4 detik berhasil dikirim ke perangkat.");
    }

    public function tareScale(Request $request): RedirectResponse
    {
        $rackCode = $request->input('rack', self::RACK);
        $device = Device::where('code', $rackCode)->firstOrFail();
        $latest = Telemetry::where('device_id', $device->id)->latest('id')->first();

        if ($latest && $latest->raw_weight !== null) {
            $device->setting()->update([
                'tare_offset' => $latest->raw_weight,
                'tared_at' => now(),
            ]);

            $seq = DeviceCommand::where('device_id', $device->id)->max('command_sequence') ?? 0;
            DeviceCommand::create([
                'command_id' => (string) Str::uuid(),
                'device_id' => $device->id,
                'target_boot_id' => (string) Str::uuid(),
                'command_sequence' => $seq + 1,
                'type' => 'tare',
                'payload' => ['tare' => true],
                'status' => 'pending',
                'lease_ms' => 0,
                'expires_at' => now()->addSeconds(30),
            ]);

            return back()->with('success', "Timbangan berhasil di-tare ke titik nol ({$latest->raw_weight}).");
        }

        return back()->with('error', "Belum ada pembacaan sensor terbaru untuk melakukan tare.");
    }

    private function dashboardData(): array
    {
        $device = Device::with(['setting.bottle.athlete', 'activeSession.athlete', 'activeSession.bottle'])
            ->where('code', self::RACK)->first();

        $latest = $device ? Telemetry::where('device_id', $device->id)->latest('id')->first() : null;
        $session = $device ? UsageSession::with(['athlete', 'bottle'])->where('device_id', $device->id)->where('status', 'active')->latest('id')->first() : null;
        $today = Carbon::today('Asia/Jakarta');

        $refillQuery = Refill::whereHas('device', fn ($q) => $q->where('code', self::RACK));
        if ($session && $session->athlete_id) {
            $refillQuery->where('athlete_id', $session->athlete_id);
        }

        $todayCount = (clone $refillQuery)->where('status', 'completed')->where('is_success_counted', true)
            ->where(function ($q) use ($today) {
                $q->whereDate('finished_at', $today)->orWhere(function ($q2) use ($today) {
                    $q2->whereNull('finished_at')->whereDate('created_at', $today);
                });
            })->count();
        $totalCount = (clone $refillQuery)->where('status', 'completed')->where('is_success_counted', true)->count();

        $activeRefill = $device ? Refill::where('device_id', $device->id)->whereIn('status', ['pending', 'filling', 'paused'])->latest('id')->first() : null;
        $unresolved = $device ? DeviceEvent::where('device_id', $device->id)->whereNull('resolved_at')->count() : 0;
        $lastSeen = $device?->last_seen_at;
        $online = $lastSeen && $lastSeen->gt(now()->subSeconds(15));
        
        $rawPercent = $latest?->water_percent !== null ? (float) $latest->water_percent : null;
        $rawGrams = $latest?->water_weight_g !== null ? (float) $latest->water_weight_g : null;
        $rawTotal = $latest?->total_weight_g !== null ? (float) $latest->total_weight_g : null;
        $capacity = $session?->water_capacity_g ?? $device?->setting?->bottle?->water_capacity_g ?? 700;
        $emptyWeightG = (float) ($session?->bottle_empty_weight_g ?? $device?->setting?->bottle?->empty_weight_g ?? 19);

        if ($latest && $latest->bottle_present === false) {
            $percent = 0.0;
            $waterGrams = 0.0;
            $volumeMl = 0;
            $totalWeightG = 0.0;
        } else {
            $percent = $rawPercent !== null ? max(0.0, $rawPercent) : null;
            $waterGrams = $rawGrams !== null ? max(0.0, $rawGrams) : null;
            $totalWeightG = $rawTotal !== null ? max(0.0, $rawTotal) : null;
            $volumeMl = $waterGrams !== null ? max(0, (int) round($waterGrams)) : null;
            if ($percent !== null && $percent <= 0.0) {
                $volumeMl = 0;
            }
        }

        $stopPercent = (float) ($device?->setting?->stop_percent ?? 80.0);
        $waterStatus = 'BELUM ADA DATA';

        if ($latest && $latest->bottle_present === false) {
            $waterStatus = 'BOTOL DIANGKAT';
        } elseif ($percent !== null) {
            $p = (float) $percent;
            $waterStatus = $p >= $stopPercent ? 'PENUH' : ($p >= 50 ? 'SEDANG' : ($p > 30 ? 'CUKUP' : 'PERLU REFILL'));
        }

        if ($activeRefill && in_array($activeRefill->status, ['pending', 'filling', 'paused'], true)) {
            if ($activeRefill->finish_reason === 'SUMBER_AIR_KOSONG') {
                $waterStatus = 'SUMBER AIR KOSONG';
            } elseif ($activeRefill->status === 'paused') {
                $waterStatus = 'REFILL DIJEDA';
            } else {
                $waterStatus = 'SEDANG REFILL';
            }
        }

        $progress = null;
        if ($activeRefill && $latest && $activeRefill->target_percent > 0) {
            $startPercent = $capacity ? (($activeRefill->start_total_weight_g - ($activeRefill->bottle_empty_weight_g ?? 0)) / $capacity) * 100 : 0;
            $progress = max(0, min(100, (($percent - $startPercent) / max(1, $activeRefill->target_percent - $startPercent)) * 100));
        }

        $recentEvents = collect();
        if ($device) {
            $eventsQ = DeviceEvent::where('device_id', $device->id)
                ->whereIn('severity', ['warning', 'error']);

            if ($session && $session->athlete_id) {
                $eventsQ->where(function ($q) use ($session) {
                    $q->whereHas('refill', fn ($rq) => $rq->where('athlete_id', $session->athlete_id));
                    if ($session->started_at) {
                        $q->orWhere(function ($q2) use ($session) {
                            $q2->whereNull('refill_id')->where('occurred_at', '>=', $session->started_at);
                        });
                    }
                });
            }

            $recentEvents = $eventsQ->latest('occurred_at')->limit(5)->get();
        }

        return [
            'device' => $device,
            'latest' => $latest,
            'session' => $session,
            'activeRefill' => $activeRefill,
            'todayCount' => $todayCount,
            'totalCount' => $totalCount,
            'unresolved' => $unresolved,
            'online' => (bool) $online,
            'waterStatus' => $waterStatus,
            'percent' => $percent,
            'waterGrams' => $waterGrams,
            'totalWeightG' => $totalWeightG,
            'emptyWeightG' => $emptyWeightG,
            'volumeMl' => $volumeMl,
            'progress' => $progress,
            'recentRefills' => (clone $refillQuery)->with(['athlete', 'bottle'])->latest('started_at')->limit(6)->get(),
            'recentEvents' => $recentEvents,
            'athletes' => Athlete::where('is_active', true)->with('bottles')->orderBy('name')->get(),
            'updatedAt' => $latest?->received_at,
        ];
    }

    private function statusPayload(): array
    {
        $data = $this->dashboardData();

        $recentRefills = $data['recentRefills']->map(function ($r) {
            $duration = '—';
            if ($r->started_at && $r->finished_at) {
                $duration = $r->started_at->diffInSeconds($r->finished_at) . ' dtk';
            }
            return [
                'id' => $r->id,
                'started_time' => $r->started_at ? $r->started_at->timezone('Asia/Jakarta')->format('H:i') . ' WIB' : '—',
                'started_date' => $r->started_at ? $r->started_at->timezone('Asia/Jakarta')->format('d/m/Y') : '—',
                'athlete_name' => $r->athlete?->name ?? '—',
                'bottle_code' => $r->bottle?->code ?? '—',
                'status' => strtoupper($r->status),
                'status_raw' => $r->status,
                'duration' => $duration,
            ];
        })->values()->toArray();

        $recentEvents = $data['recentEvents']->map(function ($e) {
            return [
                'id' => $e->id,
                'type' => str_replace('_', ' ', ucfirst($e->type)),
                'message' => $e->message,
                'severity' => strtoupper($e->severity),
                'severity_raw' => $e->severity,
                'time' => $e->occurred_at ? $e->occurred_at->timezone('Asia/Jakarta')->format('H:i:s · d M Y') . ' WIB' : '—',
            ];
        })->values()->toArray();

        return [
            'percent' => $data['percent'] !== null ? round($data['percent'], 1) : null,
            'water_weight_g' => $data['waterGrams'] !== null ? round($data['waterGrams'], 1) : null,
            'total_weight_g' => $data['totalWeightG'] !== null ? round($data['totalWeightG'], 1) : null,
            'bottle_empty_weight_g' => $data['emptyWeightG'],
            'volume_ml' => $data['volumeMl'],
            'water_status' => $data['waterStatus'],
            'pump_on' => (bool) ($data['latest']?->pump_on ?? false),
            'bottle_present' => $data['latest']?->bottle_present,
            'sensor_ready' => (bool) ($data['latest']?->sensor_ready ?? false),
            'online' => $data['online'],
            'last_seen_at' => $data['device']?->last_seen_at?->toIso8601String(),
            'received_at' => $data['updatedAt']?->toIso8601String(),
            'refill_today' => $data['todayCount'],
            'refill_total' => $data['totalCount'],
            'refill_status' => $data['activeRefill']?->status,
            'target_percent' => $data['activeRefill']?->target_percent,
            'progress' => $data['progress'],
            'unresolved_alerts' => $data['unresolved'],
            'session_athlete_name' => $data['session']?->athlete?->name ?? 'Belum dipilih',
            'session_bottle_code' => $data['session']?->bottle?->code ?? '—',
            'recent_refills' => $recentRefills,
            'recent_events' => $recentEvents,
        ];
    }
}
