<?php

namespace App\Http\Controllers;

use App\Models\AccessAuditLog;
use App\Models\AccessEvent;
use App\Models\AccessGroup;
use App\Models\DeviceAlert;
use App\Models\DeviceSyncEvent;
use App\Models\Door;
use App\Models\FingerprintEnrollment;
use App\Models\PendingTerminalSync;
use App\Models\Personnel;
use App\Models\Terminal;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;

class DashboardController extends Controller
{
    public function index(DeviceManager $deviceManager)
    {
        $data = $this->gatherDashboardData();

        return view('dashboard', $data);
    }

    public function executive(): View
    {
        Gate::authorize('dashboards.executive');

        $zone = config('attendance.timezone');
        $todayStart = now($zone)->startOfDay()->utc();

        $totalPersonnel = Personnel::count();
        $activePersonnel = Personnel::where('status', Personnel::STATUS_ACTIVE)->where('valid_enabled', true)->count();
        $departments = AccessGroup::withCount('personnel')->orderBy('name')->get();

        // Attendance metrics
        $todayEvents = AccessEvent::where('occurred_at', '>=', $todayStart)->get();
        $uniquePresentToday = $todayEvents->whereNotNull('employee_no')->pluck('employee_no')->unique()->count();

        // High security zone entries (Server Room = Door 2, UPS Room = Door 3)
        $serverRoomEntries = AccessEvent::where('occurred_at', '>=', $todayStart)
            ->where(function ($q): void {
                $q->where('terminal_id', 2)->orWhere('raw_payload->doorNo', 2);
            })->count();

        $upsRoomEntries = AccessEvent::where('occurred_at', '>=', $todayStart)
            ->where(function ($q): void {
                $q->where('terminal_id', 3)->orWhere('raw_payload->doorNo', 3);
            })->count();

        $failedAttemptsToday = AccessEvent::where('occurred_at', '>=', $todayStart)->failedAttempt()->count();
        $totalAccessToday = $todayEvents->count();

        // Department attendance breakdown (single query grouped by access_group_id to avoid N+1 queries)
        $personnelByDept = Personnel::query()
            ->whereNotNull('access_group_id')
            ->whereNotNull('employee_no')
            ->get(['employee_no', 'access_group_id'])
            ->groupBy('access_group_id');

        $deptStats = $departments->map(function ($dept) use ($todayEvents, $personnelByDept): array {
            $deptEmployees = $personnelByDept->get($dept->id, collect())->pluck('employee_no')->filter()->all();
            $presentCount = $todayEvents->whereIn('employee_no', $deptEmployees)->pluck('employee_no')->unique()->count();
            $totalInDept = count($deptEmployees);
            $rate = $totalInDept > 0 ? (int) round(($presentCount / $totalInDept) * 100) : 0;

            return [
                'name' => $dept->name,
                'total' => $totalInDept,
                'present' => $presentCount,
                'rate' => $rate,
            ];
        });

        // Offline devices / security alerts
        $offlineTerminals = Terminal::where('status', Terminal::STATUS_OFFLINE)->count();
        $openAlerts = DeviceAlert::unacknowledged()->count();

        return view('dashboard.executive', compact(
            'totalPersonnel',
            'activePersonnel',
            'departments',
            'uniquePresentToday',
            'totalAccessToday',
            'serverRoomEntries',
            'upsRoomEntries',
            'failedAttemptsToday',
            'deptStats',
            'offlineTerminals',
            'openAlerts'
        ));
    }

    public function hr(): View
    {
        Gate::authorize('dashboards.hr');

        $zone = config('attendance.timezone');
        $todayStart = now($zone)->startOfDay()->utc();

        $totalPersonnel = Personnel::count();
        $activePersonnel = Personnel::where('status', Personnel::STATUS_ACTIVE)->where('valid_enabled', true)->count();
        $enrolledFingerprints = FingerprintEnrollment::where('status', FingerprintEnrollment::STATUS_CONFIRMED)
            ->distinct()->count('personnel_id');
        $drafts = Personnel::where('status', Personnel::STATUS_DRAFT)->count();

        $pendingSyncs = PendingTerminalSync::with(['personnel', 'terminal'])->limit(15)->get();
        $pendingSyncsCount = PendingTerminalSync::count();

        $departments = AccessGroup::withCount('personnel')->orderBy('name')->get();

        $recentClockIns = AccessEvent::where('occurred_at', '>=', $todayStart)
            ->whereNotNull('employee_no')
            ->orderBy('occurred_at', 'asc')
            ->limit(20)
            ->get();

        return view('dashboard.hr', compact(
            'totalPersonnel',
            'activePersonnel',
            'enrolledFingerprints',
            'drafts',
            'pendingSyncs',
            'pendingSyncsCount',
            'departments',
            'recentClockIns'
        ));
    }

    public function audit(): View
    {
        Gate::authorize('dashboards.audit');

        $zone = config('attendance.timezone');
        $todayStart = now($zone)->startOfDay()->utc();

        $stats = [
            'audit_today' => AccessAuditLog::where('created_at', '>=', $todayStart)->count(),
            'sync_events_today' => DeviceSyncEvent::where('created_at', '>=', $todayStart)->count(),
            'failed_syncs' => DeviceSyncEvent::where('created_at', '>=', $todayStart)->where('status', DeviceSyncEvent::STATUS_FAILED)->count(),
            'failed_access_today' => AccessEvent::where('occurred_at', '>=', $todayStart)->failedAttempt()->count(),
        ];

        $recentAudits = AccessAuditLog::with(['door', 'terminal'])->latest('id')->limit(25)->get();
        $recentSyncs = DeviceSyncEvent::with('terminal')->latest('id')->limit(20)->get();
        $recentAccessEvents = AccessEvent::with('terminal')->orderByDesc('occurred_at')->limit(20)->get();

        return view('dashboard.audit', compact('stats', 'recentAudits', 'recentSyncs', 'recentAccessEvents'));
    }

    public function liveData(): JsonResponse
    {
        return response()->json($this->gatherDashboardData());
    }

    private function gatherDashboardData(): array
    {
        $terminals = Terminal::all();
        $totalTerminals = $terminals->count();

        // Online means a device answered an authenticated check; read the
        // stored status rather than a cache flag that may have expired.
        $onlineTerminals = $terminals->filter->isOnline()->count();
        $offlineTerminals = $terminals->where('status', Terminal::STATUS_OFFLINE)->count();
        $controlledDoors = Door::count();
        $totalPersons = Personnel::count();
        $zone = config('attendance.timezone');
        $today = now($zone)->startOfDay();
        $sevenDaysAgo = $today->copy()->subDays(6)->utc();
        $tomorrowUtc = $today->copy()->addDay()->utc();
        $weekEvents = AccessEvent::query()
            ->where('occurred_at', '>=', $sevenDaysAgo)
            ->where('occurred_at', '<', $tomorrowUtc)
            ->pluck('occurred_at');

        $chartDays = [];
        for ($ago = 6; $ago >= 0; $ago--) {
            $dayStart = $today->copy()->subDays($ago)->utc();
            $dayEnd = $dayStart->copy()->addDay();
            $count = $weekEvents->filter(fn ($dt) => $dt && $dt >= $dayStart && $dt < $dayEnd)->count();
            $chartDays[] = ['day' => $today->copy()->subDays($ago)->format('D d M'), 'count' => $count];
        }
        $chartMax = max(5, (int) (ceil(max(array_column($chartDays, 'count')) / 5) * 5));
        foreach ($chartDays as &$bar) {
            $bar['height'] = $bar['count'] / $chartMax * 100;
        }
        unset($bar);
        $eventsTodayCount = $chartDays[6]['count'];
        $recentEvents = AccessEvent::with(['terminal', 'personnel'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(function ($event) use ($zone) {
                $occurred = $event->occurred_at ? $event->occurred_at->copy()->timezone($zone) : null;
                $timeFormatted = $occurred
                    ? ($occurred->isToday()
                        ? 'Today, '.$occurred->format('H:i:s')
                        : ($occurred->isYesterday()
                            ? 'Yesterday, '.$occurred->format('H:i:s')
                            : $occurred->format('M d, H:i:s')))
                    : 'Just now';

                $personName = $event->person_name ?: $event->personnel?->name;
                $displayName = $personName ?: ($event->employee_no ? 'Employee #'.$event->employee_no : 'Door / Sensor Event');
                $method = $event->methodLabel();
                $outcome = $event->outcomeLabel();

                $outcomeClass = match ($outcome) {
                    'Granted' => 'badge-success',
                    'Denied' => 'badge-danger',
                    'Exit' => 'badge-info',
                    default => 'badge-muted'
                };

                return [
                    'id' => $event->id,
                    'time' => $occurred?->toIso8601String(),
                    'time_formatted' => $timeFormatted,
                    'time_relative' => $occurred?->diffForHumans(),
                    'name' => $displayName,
                    'employeeNoString' => $personName ? $event->employee_no : null,
                    'major' => $event->major,
                    'minor' => $event->minor,
                    'method' => $method,
                    'outcome' => $outcome,
                    'outcome_class' => $outcomeClass,
                    'terminal_name' => $event->terminal?->name ?? 'Main Entrance',
                ];
            })->all();
        $formattedDateTime = now($zone)->format('D, d M Y | H:i');
        $terminalProgress = $totalTerminals > 0 ? max(20, min(100, ($onlineTerminals / $totalTerminals) * 100)) : 50;

        $healthPercent = $totalTerminals > 0 ? (int) round(($onlineTerminals / $totalTerminals) * 100) : 100;
        $healthTitle = $offlineTerminals === 0 ? 'System Operational' : ($offlineTerminals === 1 ? '1 Device Offline' : $offlineTerminals.' Devices Offline');
        $healthSubtitle = $offlineTerminals === 0
            ? 'All '.$onlineTerminals.' of '.$totalTerminals.' controllers active & connected.'
            : $onlineTerminals.' of '.$totalTerminals.' devices online. Review offline units.';

        return compact(
            'totalTerminals',
            'onlineTerminals',
            'offlineTerminals',
            'controlledDoors',
            'totalPersons',
            'eventsTodayCount',
            'recentEvents',
            'chartDays',
            'chartMax',
            'formattedDateTime',
            'terminalProgress',
            'healthPercent',
            'healthTitle',
            'healthSubtitle'
        );
    }
}
