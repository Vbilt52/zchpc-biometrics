<?php

namespace App\Jobs;

use App\Services\PendingTerminalSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Processes due controller writes in bounded batches. A batch that cannot
 * finish (an offline terminal with a long timeout) leaves the remaining rows
 * queued for the next scheduled run instead of holding a worker indefinitely.
 */
class ProcessAccessSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly ?int $limit = null)
    {
        $this->timeout = max(120, (int) config('access.sync.timeout_seconds', 900));
        $this->onQueue((string) config('access.sync.queue', 'default'));
    }

    public function handle(PendingTerminalSyncService $syncs): void
    {
        $syncs->processDue($this->limit ?? (int) config('access.sync.batch_limit', 25));
    }
}
