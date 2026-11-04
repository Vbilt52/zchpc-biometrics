<?php

namespace App\Console\Commands;

use App\Services\PendingTerminalSyncService;
use Illuminate\Console\Command;

class ProcessPendingTerminalSyncs extends Command
{
    protected $signature = 'terminals:sync-pending {--limit=100 : Maximum queued syncs to process}';

    protected $description = 'Retry personnel synchronization for terminals that were previously offline';

    public function handle(PendingTerminalSyncService $syncs): int
    {
        $result = $syncs->processDue((int) $this->option('limit'));
        $this->info("{$result['synced']} synchronization(s) completed; {$result['pending']} remain queued.");

        return self::SUCCESS;
    }
}
