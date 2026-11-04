<?php

namespace App\Http\Controllers;

use App\Models\DeviceAlert;
use App\Models\Terminal;
use App\Services\DeviceActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DeviceAlertController extends Controller
{
    public function __construct(private readonly DeviceActivityLogger $activity) {}

    public function index(Request $request): View
    {
        Gate::authorize('devices.view');

        $validated = $request->validate([
            'state' => ['nullable', Rule::in(['open', 'acknowledged', 'all'])],
            'severity' => ['nullable', Rule::in([
                DeviceAlert::SEVERITY_INFO,
                DeviceAlert::SEVERITY_WARNING,
                DeviceAlert::SEVERITY_CRITICAL,
            ])],
            'type' => ['nullable', 'string', 'max:40'],
            'terminal_id' => ['nullable', 'integer', 'exists:terminals,id'],
        ]);

        $filters = [
            'state' => $validated['state'] ?? 'open',
            'severity' => $validated['severity'] ?? null,
            'type' => $validated['type'] ?? null,
            'terminal_id' => isset($validated['terminal_id']) ? (int) $validated['terminal_id'] : null,
        ];

        $alerts = DeviceAlert::query()
            ->when($filters['state'] === 'open', fn ($query) => $query->whereNull('acknowledged_at'))
            ->when($filters['state'] === 'acknowledged', fn ($query) => $query->whereNotNull('acknowledged_at'))
            ->when($filters['severity'] !== null, fn ($query) => $query->where('severity', $filters['severity']))
            ->when($filters['type'] !== null, fn ($query) => $query->where('type', $filters['type']))
            ->when($filters['terminal_id'] !== null, fn ($query) => $query->where('terminal_id', $filters['terminal_id']))
            ->orderByDesc('last_occurred_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('devices.alerts', [
            'alerts' => $alerts,
            'filters' => $filters,
            'terminals' => Terminal::query()->orderBy('name')->get(['id', 'name']),
            'types' => DeviceAlert::query()->distinct()->orderBy('type')->pluck('type'),
            'openCount' => DeviceAlert::query()->unacknowledged()->count(),
            'criticalCount' => DeviceAlert::query()->unacknowledged()->where('severity', DeviceAlert::SEVERITY_CRITICAL)->count(),
        ]);
    }

    public function acknowledge(Request $request, DeviceAlert $alert): RedirectResponse
    {
        Gate::authorize('devices.alerts.acknowledge');

        if ($alert->acknowledged_at === null) {
            $alert->forceFill([
                'acknowledged_at' => now(),
                'acknowledged_by' => $request->user()->id,
                'acknowledged_by_name' => $request->user()->name,
            ])->save();

            $this->activity->log(
                null,
                $request->user(),
                'alert_acknowledged',
                'info',
                "Acknowledged “{$alert->title}” for {$alert->terminal_name}.",
                ['alert_id' => $alert->id],
                $request->ip(),
            );
        }

        return back()->with('success', 'Alert acknowledged.');
    }

    public function acknowledgeAll(Request $request): RedirectResponse
    {
        Gate::authorize('devices.alerts.acknowledge');

        $count = DeviceAlert::query()->unacknowledged()->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => $request->user()->id,
            'acknowledged_by_name' => $request->user()->name,
            'updated_at' => now(),
        ]);

        if ($count > 0) {
            $this->activity->log(
                null,
                $request->user(),
                'alert_acknowledged',
                'info',
                "Acknowledged {$count} device alert(s).",
                ['count' => $count],
                $request->ip(),
            );
        }

        return back()->with('success', $count === 0 ? 'There were no open alerts.' : "{$count} alert(s) acknowledged.");
    }
}
