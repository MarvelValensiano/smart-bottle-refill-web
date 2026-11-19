<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\Bottle;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceEvent;
use App\Models\Refill;
use App\Models\Telemetry;
use App\Models\UsageSession;
use App\Services\RefillControlService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request, RefillControlService $controlService): View
    {
        $selectedRack = $request->query('rack', 'rack01');
        $data = $this->buildDashboardData($selectedRack, $controlService);
        return view('dashboard', $data);
    }

    public function apiStatus(Request $request, RefillControlService $controlService): JsonResponse
    {
        $selectedRack = $request->query('rack', 'rack01');
        $data = $this->buildDashboardData($selectedRack, $controlService);
        return response()->json($data);
    }

    public function toggleRefill(Request $request): RedirectResponse
    {
        $rackCode = $request->input('rack', 'rack01');
        $device = Device::where('code', $rackCode)->firstOrFail();
        $session = UsageSession::where('device_id', $device->id)
            ->where('status', 'active')
            ->first();

        if ($session) {
            $newStatus = !$session->refill_permitted;
            $session->update([
                'refill_permitted' => $newStatus,
            ]);

            $device->setting()->update([
                'auto_enabled' => $newStatus,
            ]);
        }

        return back()->with('success', "Status izin refill untuk {$device->name} berhasil diperbarui.");
    }

    public function resetAlarm(Request $request): RedirectResponse
    {
        $rackCode = $request->input('rack', 'rack01');
        $device = Device::where('code', $rackCode)->firstOrFail();
        $session = UsageSession::where('device_id', $device->id)
            ->where('status', 'active')
            ->first();

        if ($session) {
            $session->update([
                'is_locked' => false,
                'lock_reason' => null,
            ]);
        }

        DeviceEvent::where('device_id', $device->id)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);

        return back()->with('success', "Kunci gangguan untuk {$device->name} berhasil dibuka dan alarm direset.");
    }

    public function switchSession(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'rack' => 'required|string',
            'athlete_id' => 'required|exists:athletes,id',
            'bottle_id' => 'required|exists:bottles,id',
        ]);

        $device = Device::where('code', $validated['rack'])->firstOrFail();

        // 1. Tutup sesi aktif lama di rack ini
        UsageSession::where('device_id', $device->id)
            ->where('status', 'active')
            ->update([
                'status' => 'completed',
                'ended_at' => now(),
            ]);

        // 2. Ambil profil botol
        $bottle = Bottle::findOrFail($validated['bottle_id']);

        // 3. Buat sesi baru
        $sessionCount = UsageSession::count() + 1;
        $sessionCode = 'SES-' . strtoupper(substr($device->code, 0, 4)) . '-' . str_pad($sessionCount, 3, '0', STR_PAD_LEFT);

        UsageSession::create([
            'session_code' => $sessionCode,
            'device_id' => $device->id,
            'athlete_id' => $validated['athlete_id'],
            'bottle_id' => $bottle->id,
            'status' => 'active',
            'refill_permitted' => true,
            'is_locked' => false,
            'bottle_empty_weight_g' => $bottle->empty_weight_g > 0 ? $bottle->empty_weight_g : 19.0,
            'water_capacity_g' => $bottle->water_capacity_g > 0 ? $bottle->water_capacity_g : 700.0,
            'started_at' => now(),
        ]);

        $device->setting()->update([
            'bottle_id' => $bottle->id,
            'auto_enabled' => true,
        ]);

        return back()->with('success', "Sesi baru {$sessionCode} berhasil diaktifkan pada {$device->name}.");
    }

    public function forceStopPump(Request $request): RedirectResponse
    {
        $rackCode = $request->input('rack', 'rack01');
        $device = Device::where('code', $rackCode)->firstOrFail();

        $activeRefill = Refill::where('device_id', $device->id)
            ->where('status', 'filling')
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
        $rackCode = $request->input('rack', 'rack01');
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
        $rackCode = $request->input('rack', 'rack01');
        $device = Device::where('code', $rackCode)->firstOrFail();
        $latest = Telemetry::where('device_id', $device->id)->latest('id')->first();

        if ($latest && $latest->raw_weight !== null) {
            $device->setting()->update([
                'tare_offset' => $latest->raw_weight,
                'tared_at' => now(),
            ]);

            // Kirim juga perintah tare ke firmware via MQTT
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

    private function buildDashboardData(string $selectedRackCode, RefillControlService $controlService): array
    {
        $todayWib = Carbon::today('Asia/Jakarta');

        // Pastikan ketiga rack ada di database
        $rackCodes = ['rack01', 'rack02', 'rack03'];
        $devices = Device::whereIn('code', $rackCodes)->get();

        // Data rekap untuk ketiga rack (Per-Rack Counters)
        $racksSummary = [];
        $totalRefillAllRacksToday = 0;
        $totalRefillAllRacksCumulative = 0;

        foreach ($rackCodes as $code) {
            $dev = $devices->firstWhere('code', $code);
            if (!$dev) {
                $dev = Device::create(['code' => $code, 'name' => 'Smart Refill — ' . ucfirst($code)]);
            }

            $sess = UsageSession::where('device_id', $dev->id)->where('status', 'active')->with(['athlete', 'bottle'])->first();
            $latest = Telemetry::where('device_id', $dev->id)->latest('id')->first();
            $status = $controlService->getStatusLabel($latest, $dev, $sess);

            $countToday = Refill::where('device_id', $dev->id)
                ->where('status', 'completed')
                ->where('is_success_counted', true)
                ->whereDate('finished_at', $todayWib)
                ->count();

            $countTotal = Refill::where('device_id', $dev->id)
                ->where('status', 'completed')
                ->where('is_success_counted', true)
                ->count();

            $totalRefillAllRacksToday += $countToday;
            $totalRefillAllRacksCumulative += $countTotal;

            $racksSummary[$code] = [
                'device' => $dev,
                'session' => $sess,
                'latest' => $latest,
                'statusInfo' => $status,
                'refill_today' => $countToday,
                'refill_total' => $countTotal,
            ];
        }

        // Rack yang sedang dipilih untuk fokus panel kontrol
        $selectedRackData = $racksSummary[$selectedRackCode] ?? $racksSummary['rack01'];
        $selectedDevice = $selectedRackData['device'];
        $selectedSession = $selectedRackData['session'];
        $selectedLatest = $selectedRackData['latest'];
        $selectedStatusInfo = $selectedRackData['statusInfo'];

        $athletesCount = Athlete::count();
        $bottlesCount = Bottle::count();

        // Ringkasan Tim
        $bottles = Bottle::with('athlete')->get();
        $teamSummary = $bottles->map(function (Bottle $b) use ($racksSummary, $todayWib) {
            // Cek apakah botol ini sedang ada di salah satu rack
            $placedInRack = null;
            $lastPercent = null;
            $lastWeight = null;
            $lastTime = '—';

            foreach ($racksSummary as $code => $rData) {
                if ($rData['session'] && $rData['session']->bottle_id === $b->id) {
                    $placedInRack = strtoupper($code);
                    if ($rData['latest']) {
                        $lastPercent = $rData['latest']->water_percent;
                        $lastWeight = $rData['latest']->water_weight_g;
                        $lastTime = $rData['latest']->received_at ? $rData['latest']->received_at->timezone('Asia/Jakarta')->format('H:i:s') : '—';
                    }
                    break;
                }
            }

            $countToday = Refill::where('bottle_id', $b->id)
                ->where('status', 'completed')
                ->where('is_success_counted', true)
                ->whereDate('finished_at', $todayWib)
                ->count();

            $countTotal = Refill::where('bottle_id', $b->id)
                ->where('status', 'completed')
                ->where('is_success_counted', true)
                ->count();

            return [
                'bottle' => $b,
                'athlete' => $b->athlete,
                'is_at_slot' => ($placedInRack !== null),
                'placed_in_rack' => $placedInRack,
                'last_percent' => $lastPercent,
                'last_weight' => $lastWeight,
                'last_time' => $lastTime,
                'refill_today' => $countToday,
                'refill_total' => $countTotal,
            ];
        });

        // Riwayat Refill Terbaru (Semua Rack)
        $recentRefills = Refill::with(['athlete', 'bottle', 'device'])
            ->latest('id')
            ->limit(10)
            ->get();

        $allAthletes = Athlete::where('is_active', true)->with('bottles')->get();

        return [
            'selectedRackCode' => $selectedRackCode,
            'device' => $selectedDevice,
            'session' => $selectedSession,
            'latest' => $selectedLatest,
            'statusInfo' => $selectedStatusInfo,
            'racksSummary' => $racksSummary,
            'athletesCount' => $athletesCount,
            'bottlesCount' => $bottlesCount,
            'refillCountToday' => $totalRefillAllRacksToday,
            'refillCountTotal' => $totalRefillAllRacksCumulative,
            'teamSummary' => $teamSummary,
            'recentRefills' => $recentRefills,
            'allAthletes' => $allAthletes,
        ];
    }
}
