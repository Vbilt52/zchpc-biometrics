<?php

namespace App\Services;

use App\Models\Terminal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Shaykhnazar\HikvisionIsapi\Services\DeviceService;

/**
 * Checks one device and records the outcome.
 *
 * Online means an authenticated ISAPI read (device info) succeeded. A TCP
 * socket being open, a cached flag, or a previous success never marks a device
 * online: every check calls the device. Offline means no connection at all;
 * error means the device answered but the response or credentials were not
 * usable. Those are kept apart because the remedies are different.
 */
class DeviceHealthService
{
    public function __construct(
        private readonly DeviceManager $deviceManager,
        private readonly NetworkReachability $reachability,
        private readonly DeviceAlertService $alerts,
        private readonly DeviceActivityLogger $activity,
        private readonly DeviceErrorInterpreter $errors,
    ) {}

    /**
     * @param  array{reachable: bool, latency_ms: int|null, error: string|null}|null  $reachabilityResult
     *                                                                                                     A reachability result already obtained for this device (bulk refresh/diagnostics).
     * @return array{ok: bool, status: string, category: string, message: string, latency_ms: int|null, info: array, checked_at: Carbon, skipped: bool}
     */
    public function check(
        Terminal $terminal,
        string $source = 'manual',
        ?User $actor = null,
        ?int $attempts = null,
        ?array $reachabilityResult = null,
    ): array {
        if (! $terminal->isApiManaged()) {
            return $this->result(
                false,
                Terminal::STATUS_UNKNOWN,
                'unsupported',
                'This reader has no remote API, so its connectivity cannot be checked. Its door operation is local to the reader.',
                null,
                [],
                true,
            );
        }

        // Two administrators, or a scheduled run and a manual refresh, must not
        // probe the same embedded device simultaneously.
        $lock = Cache::lock('device-check:'.$terminal->id, 60);

        if (! $lock->get()) {
            return $this->result(
                false,
                $terminal->status,
                'busy',
                'Another connectivity check for this device is already running. Its result will appear shortly.',
                null,
                [],
                true,
            );
        }

        try {
            Cache::put('device-check-running:'.$terminal->id, true, 60);
            $terminal->refresh();
            $previousStatus = $terminal->status;
            $attempts = max(1, $attempts ?? 1);
            $outcome = null;

            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                $outcome = $this->probe($terminal, $attempt === 1 ? $reachabilityResult : null);

                if ($outcome['ok'] || ! $outcome['retryable'] || $attempt === $attempts) {
                    break;
                }

                $backoff = max(0, (int) config('devices.monitor.retry_backoff_ms', 400)) * (2 ** ($attempt - 1));
                usleep(min(3000, $backoff) * 1000);
            }

            $now = now();
            $attributes = [
                'status' => $outcome['status'],
                'status_source' => $source,
                'last_checked_at' => $now,
                'last_latency_ms' => $outcome['latency_ms'],
            ];

            if ($outcome['ok']) {
                $attributes += [
                    'last_seen_at' => $now,
                    'last_error' => null,
                    'last_error_at' => null,
                    'consecutive_failures' => 0,
                ];
            } else {
                $attributes += [
                    'last_error' => $outcome['message'],
                    'last_error_at' => $now,
                    'consecutive_failures' => $terminal->consecutive_failures + 1,
                ];
            }

            $terminal->forceFill($attributes)->save();
            $terminal->refresh();

            $this->publishCache($terminal, $outcome['ok'], $outcome['info']);
            $this->alerts->deviceChecked($terminal, $previousStatus, $outcome);

            $action = match ($source) {
                'reconnect' => 'reconnect',
                'diagnostics' => 'diagnostics',
                'scheduled' => 'status_change',
                default => 'refresh',
            };

            // Scheduled checks are routine; only a status change is history
            // worth keeping. An administrator-initiated check is always kept.
            if ($actor !== null || $previousStatus !== $outcome['status']) {
                $this->activity->log(
                    $terminal,
                    $actor,
                    $action,
                    $outcome['ok'] ? 'success' : 'failure',
                    $outcome['message'],
                    ['source' => $source, 'previous_status' => $previousStatus, 'status' => $outcome['status']],
                    DeviceActivityLogger::requestIp(),
                );
            }

            return $this->result(
                $outcome['ok'],
                $outcome['status'],
                $outcome['category'],
                $outcome['message'],
                $outcome['latency_ms'],
                $outcome['info'],
            );
        } finally {
            Cache::forget('device-check-running:'.$terminal->id);
            $lock->release();
        }
    }

    /**
     * @param  array{reachable: bool, latency_ms: int|null, error: string|null}|null  $prechecked
     * @return array{ok: bool, status: string, category: string, message: string, latency_ms: int|null, retryable: bool, info: array}
     */
    private function probe(Terminal $terminal, ?array $prechecked): array
    {
        $reach = $prechecked ?? $this->reachability->probe(
            $terminal->ip,
            (int) $terminal->port,
            (float) config('devices.reachability_timeout', 2.0),
        );

        if (! $reach['reachable']) {
            $detail = trim((string) ($reach['error'] ?? ''));

            return [
                'ok' => false,
                'status' => Terminal::STATUS_OFFLINE,
                'category' => 'network',
                'message' => "No connection to {$terminal->ip}:{$terminal->port}. The device may be powered off, disconnected from the network, or blocking this server."
                    .($detail !== '' ? " {$detail}" : ''),
                'latency_ms' => $reach['latency_ms'],
                'retryable' => true,
                'info' => [],
            ];
        }

        $started = microtime(true);

        try {
            $client = $this->deviceManager->device($terminal->name);
            $info = DeviceInfoValidator::validate((new DeviceService($client))->getInfo());
            $latency = max(1, (int) round((microtime(true) - $started) * 1000));

            return [
                'ok' => true,
                'status' => Terminal::STATUS_ONLINE,
                'category' => 'authenticated',
                'message' => 'Online — authenticated device response received in '.$latency.' ms.',
                'latency_ms' => $latency,
                'retryable' => false,
                'info' => is_array($info) ? $info : [],
            ];
        } catch (\Throwable $error) {
            $interpreted = $this->errors->interpret($error);

            return [
                'ok' => false,
                'status' => $interpreted['category'] === 'network' ? Terminal::STATUS_OFFLINE : Terminal::STATUS_ERROR,
                'category' => $interpreted['category'],
                'message' => $interpreted['message'],
                'latency_ms' => max(1, (int) round((microtime(true) - $started) * 1000)),
                'retryable' => $interpreted['retryable'],
                'info' => [],
            ];
        }
    }

    /**
     * Keep the caches the rest of the dashboard already reads in step with the
     * persisted status. The cache is a display convenience only; device status
     * itself is stored in the database with its timestamps.
     */
    private function publishCache(Terminal $terminal, bool $online, array $info): void
    {
        Cache::put("terminal.{$terminal->id}.online", $online, 90);

        if ($online && $info !== []) {
            Cache::put("terminal.{$terminal->id}.info", $info, 90);
        } elseif (! $online) {
            Cache::forget("terminal.{$terminal->id}.info");
        }
    }

    /**
     * @param  array<string, mixed>  $info
     * @return array{ok: bool, status: string, category: string, message: string, latency_ms: int|null, info: array, checked_at: Carbon, skipped: bool}
     */
    private function result(
        bool $ok,
        string $status,
        string $category,
        string $message,
        ?int $latency,
        array $info,
        bool $skipped = false,
    ): array {
        return [
            'ok' => $ok,
            'status' => $status,
            'category' => $category,
            'message' => $message,
            'latency_ms' => $latency,
            'info' => $info,
            'checked_at' => now(),
            'skipped' => $skipped,
        ];
    }
}
