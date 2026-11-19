<?php

namespace App\Console\Commands;

use App\Services\FingerprintInventoryService;
use Illuminate\Console\Command;

class RefreshFingerprintInventory extends Command
{
    protected $signature = 'fingerprints:inventory';

    protected $description = 'Record fingerprint slot metadata from online terminals without storing biometric templates';

    public function handle(FingerprintInventoryService $inventory): int
    {
        $this->info(json_encode($inventory->refresh()));

        return self::SUCCESS;
    }
}
