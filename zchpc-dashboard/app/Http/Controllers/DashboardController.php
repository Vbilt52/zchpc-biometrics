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
use App\Models\TerminalEventSync;
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
        $todayEvents = AccessEvent::where('occurred_at', '>=', $todayStart)->grantedAccess()->get(['employee_no']);
        $uniquePresentToday = $todayEvents->whereNotNull('employee_no')->pluck('employee_no')->unique()->count();

        // Match named controllers; channel numbers are only unique within a controller.
        $serverRoomEntries = AccessEvent::where('occurred_at', '>=', $todayStart)
            ->grantedAccess()->whereHas('terminal', fn ($q) => $q->whereRaw('LOWER(name) = ?', ['server room']))->count();

        $upsRoomEntries = AccessEvent::where('occurred_at', '>=', $todayStart)
            ->grantedAccess()->whereHas('terminal', fn ($q) => $q->whereRaw('LOWER(name) = ?', ['ups room']))->count();

        $failedAttemptsToday = AccessEvent::where('occurred_at', '>=', $todayStart)->failedAttempt()->count();
        $totalAccessToday = AccessEvent::where('occurred_at', '>=', $todayStart)->count();

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

        $totalPersonnel = Personnel::count();
        $activePersonnel = Personnel::where('status', Personnel::STATUS_ACTIVE)->where('valid_enabled', true)->count();
        $enrolledFingerprints = FingerprintEnrollment::where('status', FingerprintEnrollment::STATUS_CONFIRMED)
            ->distinct()->count('personnel_id');
        $drafts = Personnel::where('status', Personnel::STATUS_DRAFT)->count();

        $pendingSyncs = PendingTerminalSync::with(['personnel', 'terminal'])->limit(15)->get();
        $pendingSyncsCount = PendingTerminalSync::count();

        $departments = AccessGroup::withCount('personnel')->orderBy('name')->get();

        $activity = $this->hrActivityData();

        return view('dashboard.hr', compact(
            'totalPersonnel',
            'activePersonnel',
            'enrolledFingerprints',
            'drafts',
            'pendingSyncs',
            'pendingSyncsCount',
            'departments',
            'activity'
        ));
    }

    public function hrActivity(): JsonResponse
    {
        Gate::authorize('dashboards.hr');

        return response()->json($this->hrActivityData())->header('Cache-Control', 'private, no-store');
    }

    private function hrActivityData(): array
    {
        $zone = config('attendance.timezone');
        $today = now($zone)->startOfDay();
        $events = AccessEvent::with(['personnel', 'terminal.doors'])
            ->where('occurred_at', '>=', $today->copy()->utc())
            ->where('occurred_at', '<=', now()->utc())
            ->whereNotNull('employee_no')->where('employee_no', '!=', '')
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(50)->get();
        $checkpoints = TerminalEventSync::all()->keyBy('terminal_id');

        return [
            'date' => $today->format('d M Y'),
            'timezone' => $zone,
            'updated_at' => now($zone)->format('H:i:s'),
            'events' => $events->map(fn (AccessEvent $event) => [
                'id' => $event->id,
                'time' => $event->occurred_at->copy()->timezone($zone)->format('H:i:s'),
                'name' => $event->displayName(),
                'employee_id' => $event->employeeLabel(),
                'terminal' => $event->terminal?->name ?? 'Unknown terminal',
                'door' => $event->entranceLabel(),
                'direction' => $event->directionLabel(),
                'method' => $event->methodLabel(),
                'outcome' => $event->outcomeLabel(),
            ])->all(),
            'terminals' => Terminal::apiManaged()->orderBy('name')->get()->map(function (Terminal $terminal) use ($checkpoints, $zone) {
                $state = $checkpoints->get($terminal->id);
                $fresh = $terminal->isOnline() && $state?->last_success_at?->gte(now()->subMinutes(2))
                    && $state?->through_at?->gte(now()->subMinutes(2)) && ! $state?->last_error;

                return [
                    'name' => $terminal->name,
                    'status' => ! $terminal->isOnline() ? $terminal->statusLabel() : ($fresh ? 'Current' : 'Delayed'),
                    'through' => $state?->through_at?->copy()->timezone($zone)->format('d M H:i:s'),
                ];
            })->all(),
        ];
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
        $recentAccessEvents = AccessEvent::with(['terminal', 'personnel'])->orderByDesc('occurred_at')->limit(20)->get();

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
        $weekQuery = AccessEvent::query()
            ->where('occurred_at', '>=', $sevenDaysAgo)
            ->where('occurred_at', '<', $tomorrowUtc);
        for ($ago = 6; $ago >= 0; $ago--) {
            $start = $today->copy()->subDays($ago)->utc();
            $weekQuery->selectRaw('SUM(CASE WHEN occurred_at >= ? AND occurred_at < ? THEN 1 ELSE 0 END) AS day_'.$ago, [$start, $start->copy()->addDay()]);
        }
        $weekCounts = $weekQuery->first();

        $chartDays = [];
        for ($ago = 6; $ago >= 0; $ago--) {
            $dayStart = $today->copy()->subDays($ago)->utc();
            $dayEnd = $dayStart->copy()->addDay();
            $count = (int) $weekCounts->{'day_'.$ago};
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

                $personName = $event->personnel?->name ?: $event->person_name;
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
                    'employeeNoString' => $personName ? $event->employeeLabel() : null,
                    'major' => $event->major,
                    'minor' => $event->minor,
                    'method' => $method,
                    'outcome' => $outcome,
                    'outcome_class' => $outcomeClass,
                    'terminal_name' => $event->terminal?->name ?? 'Unknown terminal',
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
