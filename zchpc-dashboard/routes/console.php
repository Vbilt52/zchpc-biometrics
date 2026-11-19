<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Offline terminal changes remain in pending_terminal_syncs. This task retries
// them after the device returns online, without requiring an administrator to
// resubmit every user record manually.
Schedule::command('terminals:sync-pending')->everyMinute()->withoutOverlapping(30)->runInBackground();
Schedule::command('fingerprints:sync-pending')->everyMinute()->withoutOverlapping(10)->runInBackground();
// Reads slot numbers only; templates remain on the physical terminals.
Schedule::command('fingerprints:inventory')->everySixHours()->withoutOverlapping(60)->runInBackground();

// Per-door grants with an expiry are excluded from the controller plan as
// soon as they lapse; this removes the local grant and queues the revocation.
Schedule::command('access:expire-grants')->everyFiveMinutes()->withoutOverlapping(10)->runInBackground();

// Ping terminals in the background to cache their online status and fetch dashboard metrics
// without stalling the UI.
Schedule::command('terminals:ping')->everyFifteenSeconds()->withoutOverlapping(5)->runInBackground();

// Live workforce activity uses the same persisted checkpoints and overlap lock.
Schedule::command('terminals:sync-events')->everyFifteenSeconds()->withoutOverlapping(30)->runInBackground();
