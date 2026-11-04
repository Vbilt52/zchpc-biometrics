<?php

namespace App\Services;

use App\Jobs\ProcessDeviceRefreshJob;
use App\Models\DeviceRefreshItem;
use App\Models\DeviceRefreshRun;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Bulk device refresh.
 *
 * A run snapshots its device list, then a queued job probes each group of
 * devices with at most `devices.refresh_concurrency` simultaneous TCP
 * connections and authenticates the reachable ones one at a time. Progress is
 * visible from the run's items, and cancelling stops further checks without
 * interrupting an access-control operation on any device.
 */
class DeviceRefreshService
{
    public function __construct(
        private readonly DeviceHealthService $health,
        private readonly NetworkReachability $reachability,
        private readonly DeviceActivityLogger $activity,
    ) {}

    public function activeRun(): ?DeviceRefreshRun
    {
        return DeviceRefreshRun::query()->active()->latest('id')->first();
    }

    /**
     * @param  list<int>  $ids
     * @return array{started: bool, run: DeviceRefreshRun|null, message: string}
     */
    public function start(string $scope, array $ids = [], ?User $actor = null, ?string $ip = null): array
    {
        $lock = Cache::lock('device-refresh-start', 10);

        if (! $lock->get()) {
            return [
                'started' => false,
                'run' => $this->activeRun(),
                'message' => 'Another refresh request is being prepared. Try again in a moment.',
            ];
        }

        try {
            $existing = $this->activeRun();

            if ($existing !== null) {
                return [
                    'started' => false,
                    'run' => $existing,
                    'message' => 'A refresh is already in progress. Wait for it to finish or cancel it before starting another.',
                ];
            }

            $query = Terminal::query()->apiManaged();

            if ($scope === DeviceRefreshRun::SCOPE_OFFLINE) {
                $query->where('status', Terminal::STATUS_OFFLINE);
            } elseif ($scope === DeviceRefreshRun::SCOPE_SELECTED) {
                $query->whereIn('id', $ids);
            }

            $terminals = $query->orderBy('phase')->orderBy('name')->get();

            if ($terminals->isEmpty()) {
                return [
                    'started' => false,
                    'run' => null,
                    'message' => $scope === DeviceRefreshRun::SCOPE_OFFLINE
                        ? 'No devices are currently marked offline, so there was nothing to recheck.'
                        : 'No remote-managed devices matched this refresh request.',
                ];
            }

            $run = DB::transaction(function () use ($scope, $terminals, $actor): DeviceRefreshRun {
                $run = DeviceRefreshRun::query()->create([
                    'scope' => $scope,
                    'status' => DeviceRefreshRun::STATUS_QUEUED,
                    'total' => $terminals->count(),
                    'user_id' => $actor?->id,
                    'user_name' => $actor?->name ?? 'System',
                    'started_at' => now(),
                ]);

                foreach ($terminals as $terminal) {
                    $run->items()->create([
                        'terminal_id' => $terminal->id,
                        'terminal_name' => $terminal->name,
                        'status' => DeviceRefreshItem::STATUS_PENDING,
                    ]);
                }

                return $run;
            });

            $action = match ($scope) {
                DeviceRefreshRun::SCOPE_OFFLINE => 'refresh_offline',
                DeviceRefreshRun::SCOPE_SELECTED => 'refresh_selected',
                default => 'refresh_all',
            };

            $this->activity->log(
                null,
                $actor,
                $action,
                'info',
                "Requested a connectivity check for {$terminals->count()} device(s).",
                ['run_id' => $run->id, 'scope' => $scope, 'terminals' => $terminals->pluck('name')->all()],
                $ip,
            );

            ProcessDeviceRefreshJob::dispatch($run->id)->onQueue((string) config('devices.refresh_queue', 'default'));

            return [
                'started' => true,
                'run' => $run,
                'message' => "Queued {$terminals->count()} device check(s). Each device is updated as its result arrives.",
            ];
        } finally {
            $lock->release();
        }
    }

    public function cancel(DeviceRefreshRun $run, ?User $actor = null, ?string $ip = null): DeviceRefreshRun
    {
        $cancelled = 0;

        if ($run->isActive()) {
            DB::transaction(function () use ($run, &$cancelled): void {
                $run->refresh();

                if (! $run->isActive()) {
                    return;
                }

                $cancelled = $run->items()
                    ->where('status', DeviceRefreshItem::STATUS_PENDING)
                    ->update(['status' => DeviceRefreshItem::STATUS_CANCELLED, 'checked_at' => now()]);

                $run->forceFill([
                    'status' => DeviceRefreshRun::STATUS_CANCELLED,
                    'cancelled' => $run->cancelled + $cancelled,
                    'finished_at' => now(),
                    'message' => "Cancelled. {$cancelled} unchecked device(s) were not contacted.",
                ])->save();
            });

            $this->activity->log(
                null,
                $actor,
                'refresh_cancelled',
                'info',
                "Cancelled refresh run #{$run->id}; {$cancelled} device(s) were not contacted.",
                ['run_id' => $run->id],
                $ip,
            );
        }

        return $run->refresh();
    }

    /**
     * Execute a queued run. Called from the queue worker, so a slow device
     * never blocks a web request.
     */
    public function process(DeviceRefreshRun $run): void
    {
        $lock = Cache::lock('device-refresh-run:'.$run->id, (int) config('devices.refresh_timeout_seconds', 900) + 30);
        if (! $lock->get()) { return; }
        try { $this->processLocked($run); } finally { $lock->release(); }
    }

    private function processLocked(DeviceRefreshRun $run): void
    {
        $run->refresh();

        if (! $run->isActive()) {
            return;
        }

        $run->items()->where('status', DeviceRefreshItem::STATUS_CHECKING)->update(['status' => DeviceRefreshItem::STATUS_PENDING]);
        $run->forceFill(['status' => DeviceRefreshRun::STATUS_RUNNING])->save();

        $concurrency = max(1, (int) config('devices.refresh_concurrency', 4));
        $delayMs = max(0, (int) config('devices.refresh_inter_device_delay_ms', 100));
        $timeout = (float) config('devices.reachability_timeout', 2.0);
        $actor = $run->user_id !== null ? User::find($run->user_id) : null;

        $pending = $run->items()->where('status', DeviceRefreshItem::STATUS_PENDING)->orderBy('id')->get();

        foreach ($pending->chunk($concurrency) as $chunk) {
            if ($this->isCancelled($run)) {
                return;
            }

            $terminalIds = $chunk->pluck('terminal_id')->filter()->all();
            $terminals = Terminal::query()->whereIn('id', $terminalIds)->get()->keyBy('id');
            $targets = [];

            foreach ($chunk as $item) {
                $terminal = $terminals->get($item->terminal_id);

                if ($terminal !== null) {
                    $targets[$item->id] = ['host' => $terminal->ip, 'port' => (int) $terminal->port];
                }
            }

            // Network reachability is the only part done concurrently; the
            // authenticated read is serialised per device.
            $probes = $targets === [] ? [] : $this->reachability->probeMany($targets, $concurrency, $timeout);

            foreach ($chunk as $item) {
                if ($this->isCancelled($run)) {
                    return;
                }

                $item->refresh();

                if ($item->status === DeviceRefreshItem::STATUS_CANCELLED) {
                    continue;
                }

                $terminal = $terminals->get($item->terminal_id);

                if ($terminal === null || ! $terminal->isApiManaged()) {
                    $item->forceFill([
                        'status' => DeviceRefreshItem::STATUS_CANCELLED,
                        'message' => 'The device is no longer available for remote checks.',
                        'checked_at' => now(),
                    ])->save();
                    $run->increment('cancelled');

                    continue;
                }

                $item->forceFill(['status' => DeviceRefreshItem::STATUS_CHECKING])->save();

                $result = $this->health->check(
                    $terminal,
                    'refresh',
                    $actor,
                    1,
                    $probes[$item->id] ?? null,
                );

                $item->refresh();

                if ($item->status === DeviceRefreshItem::STATUS_CANCELLED) {
                    continue;
                }

                $item->forceFill([
                    'status' => $result['skipped'] ? DeviceRefreshItem::STATUS_CANCELLED : $result['status'],
                    'message' => $result['message'],
                    'latency_ms' => $result['latency_ms'],
                    'checked_at' => now(),
                ])->save();

                $run->increment('completed');

                if (! $result['ok']) {
                    $run->increment('failed');
                }

                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }

        $run->refresh();

        if (! $run->isActive()) {
            return;
        }

        $counts = $run->items()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $online = (int) ($counts[DeviceRefreshItem::STATUS_ONLINE] ?? 0);
        $offline = (int) ($counts[DeviceRefreshItem::STATUS_OFFLINE] ?? 0);
        $errors = (int) ($counts[DeviceRefreshItem::STATUS_ERROR] ?? 0);
        $run->completed = $online + $offline + $errors;
        $run->failed = $offline + $errors;
        $run->cancelled = (int) ($counts[DeviceRefreshItem::STATUS_CANCELLED] ?? 0);
        $summary = "Checked {$run->completed} of {$run->total} device(s): {$online} online, {$offline} offline, {$errors} with errors";

        $run->forceFill([
            'status' => DeviceRefreshRun::STATUS_COMPLETED,
            'finished_at' => now(),
            'message' => $summary.'.',
        ])->save();

        $this->activity->log(
            null,
            $actor,
            'refresh_completed',
            $errors === 0 && $offline === 0 ? 'success' : 'info',
            $summary.'.',
            ['run_id' => $run->id, 'online' => $online, 'offline' => $offline, 'errors' => $errors],
            DeviceActivityLogger::requestIp(),
        );
    }

    private function isCancelled(DeviceRefreshRun $run): bool
    {
        return DeviceRefreshRun::query()->whereKey($run->id)->value('status') === DeviceRefreshRun::STATUS_CANCELLED;
    }
}
