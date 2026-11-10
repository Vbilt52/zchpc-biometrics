<?php

namespace App\Services;

use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\TerminalEventSync;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class TerminalEventSyncService
{
    public function __construct(
        private readonly EventImportService $importer,
        // Optional so existing callers that construct the service directly
        // keep working; the container supplies it in normal use.
        private readonly ?DeviceAlertService $alerts = null,
    ) {}

    private function alerts(): DeviceAlertService
    {
        return $this->alerts ?? app(DeviceAlertService::class);
    }

    public function sync(Terminal $terminal): void
    {
        if (! $terminal->supports('event_import')) {
            return;
        }

        // The scheduler and a manually invoked command share this lock.
        $lock = Cache::lock('event-sync:'.$terminal->id, 1800);
        if (! $lock->get()) {
            return;
        }

        try {
            $state = TerminalEventSync::firstOrCreate(['terminal_id' => $terminal->id], [
                'started_at' => now(config('attendance.timezone'))->startOfDay()->utc(),
            ]);
            $state->update(['last_attempt_at' => now()]);
            $target = now()->subSeconds(10)->startOfSecond();
            $from = $state->through_at
                ? $state->through_at->copy()->subMinutes(2)->max($state->started_at)
                : $state->started_at->copy();
            $names = Personnel::pluck('name', 'employee_no')->all();
            $window = 3600;

            try {
                // Advance windows until caught up to the target, backing off if truncated.
                $truncationRetries = 0;
                $maxSteps = 240; // Allow catching up to 10 days of hourly windows in a single run
                $steps = 0;

                while ($from->lt($target) && $steps < $maxSteps) {
                    $steps++;
                    $to = $from->copy()->addSeconds($window)->min($target);
                    $result = $this->importer->import($terminal, $from, $to, $names);
                    if ($result['truncated']) {
                        $truncationRetries++;
                        if ($window <= 1 || $truncationRetries >= 12) {
                            throw new \RuntimeException('Event volume exceeds the per-second import limit.');
                        }
                        $window = max(1, intdiv($window, 2));
                        $state->update(['last_error' => 'High event volume: retrying a smaller time window.']);

                        continue;
                    }

                    $through = $state->through_at ? $to->copy()->max($state->through_at) : $to;
                    $state->update(['through_at' => $through, 'last_success_at' => now(), 'last_error' => null]);
                    $from = Carbon::instance($to);
                    $window = 3600;
                    $truncationRetries = 0;
                }
            } catch (\Throwable $error) {
                $message = self::errorMessage($error);
                $state->update(['last_error' => $message]);
                // Collection failures share the device's connection, so they
                // are surfaced in the same alert centre as connectivity
                // problems, with their own type and cooldown.
                $this->alerts()->syncFailed($terminal, $message);
            }
        } finally {
            $lock->release();
        }
    }

    public static function errorMessage(\Throwable $error): string
    {
        $message = $error->getMessage();
        if (str_contains($message, '67') || str_contains($message, '401')) {
            return 'The terminal rejected its credentials. Check its saved username and password.';
        }
        if (str_contains($message, 'cURL error 7') || str_contains($message, 'cURL error 28')) {
            return 'The terminal is unreachable or timed out. Collection will retry automatically.';
        }

        return 'Collection could not complete. Check the terminal connection and event-log response; the saved checkpoint has been retained.';
    }
}
