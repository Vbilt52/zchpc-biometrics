<?php

namespace App\Http\Controllers;

use App\Models\AccessEvent;
use App\Models\Door;
use App\Models\Personnel;
use App\Models\Terminal;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;

class DashboardController extends Controller
{
    public function index(DeviceManager $deviceManager)
    {
        $data = $this->gatherDashboardData();

        return view('dashboard', $data);
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
        $chartDays = [];
        for ($ago = 6; $ago >= 0; $ago--) {
            $day = $today->copy()->subDays($ago);
            $count = AccessEvent::where('occurred_at', '>=', $day->copy()->utc())
                ->where('occurred_at', '<', $day->copy()->addDay()->utc())->count();
            $chartDays[] = ['day' => $day->format('D d M'), 'count' => $count];
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
                $minor = (int) $event->minor;
                $badge = in_array($minor, [1, 38, 75], true) ? 'Fingerprint' : (in_array($minor, [13, 14, 15, 21, 22], true) ? 'PIN' : 'Access Granted');
                $badgeClass = in_array($minor, [1, 38, 75], true) ? 'badge-success-pill' : (in_array($minor, [13, 14, 15, 21, 22], true) ? 'badge-warning-pill' : 'badge-neutral-pill');

                $occurred = $event->occurred_at ? $event->occurred_at->copy()->timezone($zone) : null;
                $timeFormatted = $occurred
                    ? ($occurred->isToday()
                        ? 'Today, '.$occurred->format('H:i:s')
                        : ($occurred->isYesterday()
                            ? 'Yesterday, '.$occurred->format('H:i:s')
                            : $occurred->format('M d, H:i:s')))
                    : 'Just now';

                return [
                    'id' => $event->id,
                    'time' => $occurred?->toIso8601String(),
                    'time_formatted' => $timeFormatted,
                    'time_relative' => $occurred?->diffForHumans(),
                    'name' => $event->person_name ?: $event->personnel?->name ?: 'Unknown',
                    'employeeNoString' => $event->employee_no,
                    'major' => $event->major,
                    'minor' => $event->minor,
                    'terminal_name' => $event->terminal?->name ?? 'Main Entrance',
                    'badge' => $badge,
                    'badge_class' => $badgeClass,
                ];
            })->all();
        $formattedDateTime = now($zone)->format('D, d M Y | H:i');
        $terminalProgress = $totalTerminals > 0 ? max(20, min(100, ($onlineTerminals / $totalTerminals) * 100)) : 50;

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
            'terminalProgress'
        );
    }
}
