<?php

namespace App\Console\Commands;

use App\Models\Terminal;
use App\Services\TerminalEventSyncService;
use Illuminate\Console\Command;

class SyncTerminalEventsCommand extends Command
{
    protected $signature = 'terminals:sync-events';

    protected $description = 'Collect terminal access logs with persistent checkpoints and retries';

    public function handle(TerminalEventSyncService $sync): int
    {
        foreach (Terminal::apiManaged()->orderBy('id')->get() as $terminal) {
            try {
                $sync->sync($terminal);
            } catch (\Throwable $error) {
                $this->warn($terminal->name.': '.TerminalEventSyncService::errorMessage($error));
            }
        }

        return self::SUCCESS;
    }
}
