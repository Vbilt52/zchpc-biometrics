<?php

namespace App\Jobs;

use App\Models\DeviceRefreshRun;
use App\Services\DeviceRefreshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs a bulk device refresh away from the web request, so an offline terminal
 * with a long timeout cannot hold an administrator's browser open.
 */
class ProcessDeviceRefreshJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout;

    public function __construct(public readonly int $runId)
    {
        $this->timeout = (int) config('devices.refresh_timeout_seconds', 900);
    }

    public function handle(DeviceRefreshService $service): void
    {
        $run = DeviceRefreshRun::query()->find($this->runId);

        if ($run === null) {
            return;
        }

        $service->process($run);
    }

    public function failed(?\Throwable $error): void
    {
        $run = DeviceRefreshRun::query()->find($this->runId);

        if ($run === null || ! $run->isActive()) {
            return;
        }

        $run->forceFill([
            'status' => DeviceRefreshRun::STATUS_FAILED,
            'finished_at' => now(),
            'message' => 'The refresh run stopped before every device was checked. Retry the unfinished checks.',
        ])->save();
    }
}
