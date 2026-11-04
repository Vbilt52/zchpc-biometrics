<?php

namespace App\Http\Controllers;

use App\Models\AccessEvent;
use App\Models\Door;
use App\Models\Personnel;
use App\Models\Terminal;
use Illuminate\Support\Facades\Cache;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;

class DashboardController extends Controller
{
    public function index(DeviceManager $deviceManager)
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
        $recentEvents = AccessEvent::with(['terminal', 'personnel'])->latest('occurred_at')->limit(5)->get()->map(fn ($event) => [
            'time' => $event->occurred_at?->copy()->timezone($zone)->toIso8601String(),
            'name' => $event->person_name ?: $event->personnel?->name,
            'employeeNoString' => $event->employee_no,
            'major' => $event->major, 'minor' => $event->minor,
            'terminal_name' => $event->terminal?->name ?? 'Removed terminal',
        ])->all();
        $formattedDateTime = now($zone)->format('D, d M Y | H:i');

        return view('dashboard', compact(
            'totalTerminals',
            'onlineTerminals',
            'offlineTerminals',
            'controlledDoors',
            'totalPersons',
            'eventsTodayCount',
            'recentEvents',
            'chartDays', 'chartMax',
            'formattedDateTime'
        ));
    }
}
