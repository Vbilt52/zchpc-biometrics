<?php

namespace App\Http\Controllers;

use App\Models\DeviceAlert;
use App\Models\DeviceMonitorSettings;
use App\Models\DeviceRefreshRun;
use App\Models\Terminal;
use App\Services\DeviceActivityLogger;
use App\Services\DeviceConnectionService;
use App\Services\DeviceDiagnosticsService;
use App\Services\DeviceHealthService;
use App\Services\DeviceRefreshService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Shaykhnazar\HikvisionIsapi\Services\AccessControlService;

class DeviceController extends Controller
{
    private const STATUS_FILTERS = ['all', 'online', 'offline', 'connecting', 'error', 'attention'];

    public function __construct(
        private readonly DeviceManager $deviceManager,
        private readonly DeviceHealthService $health,
        private readonly DeviceRefreshService $refreshes,
        private readonly DeviceDiagnosticsService $diagnostics,
        private readonly DeviceConnectionService $connections,
        private readonly DeviceActivityLogger $activity,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('devices.view');

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUS_FILTERS)],
            'search' => ['nullable', 'string', 'max:64'],
            'phase' => ['nullable', 'string', 'max:100'],
        ]);

        $settings = DeviceMonitorSettings::current();
        $all = Terminal::query()->orderBy('phase')->orderBy('name')->get();
        $statusFilter = $validated['status'] ?? 'all';
        $search = trim((string) ($validated['search'] ?? ''));
        $phase = $validated['phase'] ?? null;

        $terminals = $all
            ->filter(fn (Terminal $terminal): bool => $this->matchesFilter($terminal, $statusFilter, $search, $phase, $settings))
            ->values();

        return view('devices', [
            'terminals' => $terminals,
            'stats' => $this->stats($all, $settings),
            'settings' => $settings,
            'activeRun' => $this->refreshes->activeRun(),
            'lastRun' => DeviceRefreshRun::query()->latest('id')->first(),
            'phases' => $all->pluck('phase')->filter()->unique()->values(),
            'filters' => ['status' => $statusFilter, 'search' => $search, 'phase' => $phase],
            'unacknowledgedAlerts' => DeviceAlert::query()->unacknowledged()->count(),
            'failureThreshold' => (int) config('devices.monitor.failures_before_alert', 3),
        ]);
    }

    /** Live values for the dashboard's background synchronisation. */
    public function statuses(): JsonResponse
    {
        Gate::authorize('devices.view');

        return response()->json($this->livePayload());
    }

    /**
     * Refresh one device. The check talks to the device itself; a cached flag is
     * never used to report success.
     */
    public function refresh(Request $request, Terminal $terminal): JsonResponse|RedirectResponse
    {
        Gate::authorize('devices.manage');

        if (! $terminal->isApiManaged()) {
            return $this->deviceResponse($request, false, "{$terminal->name} has no remote API, so there is nothing to check from here.", $terminal, 422);
        }

        $result = $this->health->check($terminal, 'refresh', $request->user(), 1);
        $terminal->refresh();

        $code = $result['skipped'] ? 409 : 200;
        $message = "{$terminal->name}: {$result['message']}";

        return $this->deviceResponse($request, $result['ok'], $message, $terminal, $code);
    }

    /** Queue a refresh of every device, offline devices only, or a selected group. */
    public function refreshDevices(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('devices.manage');

        $validated = $request->validate([
            'scope' => ['required', Rule::in([
                DeviceRefreshRun::SCOPE_ALL,
                DeviceRefreshRun::SCOPE_OFFLINE,
                DeviceRefreshRun::SCOPE_SELECTED,
            ])],
            'ids' => ['nullable', 'array', 'max:250'],
            'ids.*' => ['integer', 'distinct', 'exists:terminals,id'],
        ]);

        if ($validated['scope'] === DeviceRefreshRun::SCOPE_SELECTED && empty($validated['ids'])) {
            throw ValidationException::withMessages(['ids' => 'Select at least one device to refresh.']);
        }

        $result = $this->refreshes->start(
            $validated['scope'],
            array_map('intval', $validated['ids'] ?? []),
            $request->user(),
            $request->ip(),
        );

        if ($request->expectsJson()) {
            return response()->json([
                'started' => $result['started'],
                'message' => $result['message'],
                'run' => $result['run']?->progress(),
                'stats' => $this->stats(Terminal::query()->get(), DeviceMonitorSettings::current()),
            ], $result['started'] ? 202 : 409);
        }

        return back()->with($result['started'] ? 'success' : 'error', $result['message']);
    }

    public function refreshRun(DeviceRefreshRun $run): JsonResponse
    {
        Gate::authorize('devices.view');

        $run->refresh();

        return response()->json([
            'run' => $run->progress(),
            'items' => $run->items()->get()->map(fn ($item): array => [
                'terminal_id' => $item->terminal_id,
                'terminal_name' => $item->terminal_name,
                'status' => $item->status,
                'message' => $item->message,
                'latency_ms' => $item->latency_ms,
            ])->values(),
            'stats' => $this->stats(Terminal::query()->get(), DeviceMonitorSettings::current()),
        ]);
    }

    public function cancelRefresh(Request $request, DeviceRefreshRun $run): JsonResponse|RedirectResponse
    {
        Gate::authorize('devices.manage');

        $cancelled = $this->refreshes->cancel($run, $request->user(), $request->ip());

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $cancelled->message ?? 'Refresh cancelled.',
                'run' => $cancelled->progress(),
            ]);
        }

        return back()->with('success', $cancelled->message ?? 'Refresh cancelled.');
    }

    /** Read-only diagnostics: reachability, protocol, authentication and history. */
    public function diagnostics(Request $request, Terminal $terminal): JsonResponse
    {
        Gate::authorize('devices.diagnostics');

        return response()->json(
            $this->diagnostics->report($terminal, $request->user(), $request->ip())
        );
    }

    /**
     * Re-establish this application's connection. Distinct from refreshing the
     * status: cached clients and saved-credential cache are discarded first.
     * The device itself is not restarted.
     */
    public function reconnect(Request $request, Terminal $terminal): JsonResponse|RedirectResponse
    {
        Gate::authorize('devices.manage');

        $result = $this->connections->reconnect($terminal, $request->user(), $request->ip());
        $terminal->refresh();

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $result['ok'],
                'message' => $result['message'],
                'device' => $this->devicePayload($terminal),
            ], $result['ok'] ? 200 : 200);
        }

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function updateMonitorSettings(Request $request): RedirectResponse
    {
        Gate::authorize('devices.manage');

        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'interval_seconds' => [
                'required',
                'integer',
                'min:'.(int) config('devices.monitor.min_interval_seconds', 15),
                'max:'.(int) config('devices.monitor.max_interval_seconds', 3600),
            ],
            'alert_cooldown_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);

        $settings = DeviceMonitorSettings::current();
        $settings->update([
            'enabled' => $request->boolean('enabled'),
            'interval_seconds' => (int) $validated['interval_seconds'],
            'alert_cooldown_minutes' => (int) $validated['alert_cooldown_minutes'],
            'updated_by' => $request->user()->id,
            'updated_by_name' => $request->user()->name,
        ]);

        $this->activity->log(
            null,
            $request->user(),
            'monitor_settings',
            'info',
            'Automatic monitoring '.($settings->enabled ? 'enabled' : 'paused')
                ."; interval {$settings->interval_seconds}s; alert cooldown {$settings->alert_cooldown_minutes} min.",
            [],
            $request->ip(),
        );

        return back()->with('success', 'Monitoring settings saved. They apply from the next scheduled run.');
    }

    // ------------------------------------------------------------------
    // Remote door control (existing behaviour, unchanged)
    // ------------------------------------------------------------------

    public function openDoor(Request $request, string $terminalName, int $doorNo = 1)
    {
        try {
            $this->requireCapability($terminalName, 'door_control');
            $client = $this->deviceManager->device($terminalName);
            $accessControlService = new AccessControlService($client);
            $accessControlService->openDoor($doorNo);

            return back()->with('success', "Door {$doorNo} on {$terminalName} opened successfully.");
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to open door: '.$e->getMessage());
        }
    }

    public function closeDoor(Request $request, string $terminalName, int $doorNo = 1)
    {
        try {
            $this->requireCapability($terminalName, 'door_control');
            $client = $this->deviceManager->device($terminalName);
            $accessControlService = new AccessControlService($client);
            $accessControlService->closeDoor($doorNo);

            return back()->with('success', "Door {$doorNo} on {$terminalName} closed successfully.");
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to close door: '.$e->getMessage());
        }
    }

    public function getDoorStatus(string $terminalName, int $doorNo = 1)
    {
        try {
            $this->requireCapability($terminalName, 'door_control');
            $client = $this->deviceManager->device($terminalName);
            $accessControlService = new AccessControlService($client);
            $status = $accessControlService->getDoorStatus($doorNo);

            return response()->json($status);
        } catch (\Exception $e) {
            // An unreachable terminal is an expected device condition, not a
            // server fault, so report it as a service-unavailable response
            // with the same error body the caller already receives.
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    private function requireCapability(string $terminalName, string $capability): Terminal
    {
        $terminal = Terminal::where('name', $terminalName)->firstOrFail();

        abort_unless(
            $terminal->supports($capability),
            422,
            "{$terminal->name} does not provide the required {$capability} API capability."
        );

        return $terminal;
    }

    // ------------------------------------------------------------------
    // Presentation helpers
    // ------------------------------------------------------------------

    /**
     * @param  Collection<int, Terminal>  $terminals
     * @return array<string, int>
     */
    private function stats(Collection $terminals, DeviceMonitorSettings $settings): array
    {
        return [
            'total' => $terminals->count(),
            'online' => $terminals->filter(fn (Terminal $terminal): bool => $terminal->isOnline())->count(),
            'offline' => $terminals->where('status', Terminal::STATUS_OFFLINE)->count(),
            'connecting' => $terminals->filter(fn ($t) => $t->effectiveStatus() === Terminal::STATUS_CONNECTING)->count(),
            'error' => $terminals->where('status', Terminal::STATUS_ERROR)->count(),
            'attention' => $terminals->filter(fn (Terminal $terminal): bool => $terminal->needsAttention($settings))->count(),
            'unmonitored' => $terminals->reject(fn (Terminal $terminal): bool => $terminal->isApiManaged())->count(),
        ];
    }

    private function matchesFilter(Terminal $terminal, string $status, string $search, ?string $phase, DeviceMonitorSettings $settings): bool
    {
        if ($phase !== null && $phase !== '' && $terminal->phase !== $phase) {
            return false;
        }

        if ($search !== '') {
            $haystack = mb_strtolower(implode(' ', array_filter([
                $terminal->name, $terminal->phase, $terminal->location, $terminal->ip,
                $terminal->model, $terminal->integrationLabel(),
            ])));

            if (! str_contains($haystack, mb_strtolower($search))) {
                return false;
            }
        }

        return match ($status) {
            'online' => $terminal->isOnline(),
            'offline' => $terminal->status === Terminal::STATUS_OFFLINE,
            'connecting' => $terminal->status === Terminal::STATUS_CONNECTING,
            'error' => $terminal->status === Terminal::STATUS_ERROR,
            'attention' => $terminal->needsAttention($settings),
            default => true,
        };
    }

    /** @return array<string, mixed> */
    private function livePayload(): array
    {
        $settings = DeviceMonitorSettings::current();
        $terminals = Terminal::query()->orderBy('phase')->orderBy('name')->get();
        $run = $this->refreshes->activeRun();

        return [
            'stats' => $this->stats($terminals, $settings),
            'devices' => $terminals->map(fn (Terminal $terminal): array => $this->devicePayload($terminal))->values(),
            'run' => $run?->progress(),
            'run_items' => $run === null ? [] : $run->items()->get()->map(fn ($item): array => [
                'terminal_id' => $item->terminal_id,
                'status' => $item->status,
                'message' => $item->message,
            ])->values(),
            'settings' => [
                'enabled' => $settings->enabled,
                'interval_seconds' => $settings->interval_seconds,
                'last_run_at' => $this->label($settings->last_run_at),
            ],
            'server_time' => $this->label(now()),
        ];
    }

    /** @return array<string, mixed> */
    private function devicePayload(Terminal $terminal): array
    {
        return [
            'id' => $terminal->id,
            'name' => $terminal->name,
            'phase' => $terminal->phase,
            'location' => $terminal->location,
            'address' => "{$terminal->ip}:{$terminal->port}",
            'model' => $terminal->model,
            'monitored' => $terminal->isApiManaged(),
            'status' => $terminal->effectiveStatus(),
            'status_label' => $terminal->statusLabel(),
            'status_tone' => $terminal->statusTone(),
            'last_checked' => $this->label($terminal->last_checked_at),
            'last_seen' => $this->label($terminal->last_seen_at),
            'latency_ms' => $terminal->last_latency_ms,
            'error' => $terminal->last_error,
            'attention_reason' => $terminal->attentionReason(),
        ];
    }

    private function deviceResponse(
        Request $request,
        bool $ok,
        string $message,
        Terminal $terminal,
        int $status = 200,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $ok,
                'message' => $message,
                'device' => $this->devicePayload($terminal->refresh()),
                'stats' => $this->stats(Terminal::query()->get(), DeviceMonitorSettings::current()),
            ], $status);
        }

        return back()->with($ok ? 'success' : 'error', $message);
    }

    private function label(?CarbonInterface $time): string
    {
        return $time === null
            ? 'Never'
            : $time->copy()->timezone(config('attendance.timezone'))->format('d M H:i:s');
    }
}
