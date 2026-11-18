<?php

namespace App\Console\Commands;

use App\Services\FingerprintSyncService;
use Illuminate\Console\Command;

class SyncFingerprints extends Command
{
    protected $signature = 'fingerprints:sync-pending {--limit=20}';

    protected $description = 'Retry authorized fingerprint copies without storing biometric templates';

    public function handle(FingerprintSyncService $service): int
    {
        $this->info(json_encode($service->processDue((int) $this->option('limit'))));

        return self::SUCCESS;
    }
}
