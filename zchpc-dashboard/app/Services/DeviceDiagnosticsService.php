<?php

namespace App\Services;

use App\Models\DeviceActivityLog;
use App\Models\Terminal;
use App\Models\TerminalEventSync;
use App\Models\User;
use Carbon\Carbon;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;
use Shaykhnazar\HikvisionIsapi\Services\DeviceService;

/**
 * Read-only diagnostics for one device.
 *
 * Every step is a read: reachability, an authenticated device-info request,
 * and locally stored history. Nothing is written to the device, no credentials
 * are returned, and the device's saved configuration is never changed.
 */
class DeviceDiagnosticsService
{
    public function __construct(
        private readonly DeviceManager $deviceManager,
        private readonly NetworkReachability $reachability,
        private readonly DeviceErrorInterpreter $errors,
        private readonly DeviceActivityLogger $activity,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(Terminal $terminal, ?User $actor = null, ?string $ip = null): array
    {
        $timeout = (float) config('devices.reachability_timeout', 2.0);
        $reach = null;
        $protocol = [
            'tested' => false,
            'ok' => false,
            'latency_ms' => null,
            'detail' => 'Not tested because the device has no remote API.',
        ];
        $authentication = [
            'status' => 'not-tested',
            'detail' => 'No remote API is available on this reader.',
        ];
        $deviceInfo = [];
        $deviceClock = null;

        if ($terminal->isApiManaged()) {
            $reach = $this->reachability->probe($terminal->ip, (int) $terminal->port, $timeout);
            $reach['address'] = "{$terminal->ip}:{$terminal->port}";

            if ($reach['reachable']) {
                $started = microtime(true);

                try {
                    $client = $this->deviceManager->device($terminal->name);
                    $deviceInfo = DeviceInfoValidator::validate((new DeviceService($client))->getInfo());
                    $latency = max(1, (int) round((microtime(true) - $started) * 1000));
                    $protocol = [
                        'tested' => true,
                        'ok' => true,
                        'latency_ms' => $latency,
                        'detail' => 'The device answered an authenticated read-only device-info request.',
                    ];
                    $authentication = [
                        'status' => 'accepted',
                        'detail' => 'The saved device username and password were accepted.',
                    ];

                    // Read-only clock report. Drift is deliberately not
                    // calculated here: the device's timezone string format
                    // varies by model and an unverified calculation would be
                    // worse than none.
                    try {
                        $deviceTime = $client->get('/ISAPI/System/time');
                        $reported = $deviceTime['localTime'] ?? $deviceTime['Time'] ?? null;
                        $deviceClock = is_scalar($reported) ? (string) $reported : null;
                    } catch (\Throwable) {
                        $deviceClock = null;
                    }
                } catch (\Throwable $error) {
                    $interpreted = $this->errors->interpret($error);
                    $protocol = [
                        'tested' => true,
                        'ok' => false,
                        'latency_ms' => max(1, (int) round((microtime(true) - $started) * 1000)),
                        'detail' => $interpreted['message'],
                        'category' => $interpreted['category'],
                    ];
                    $authentication = [
                        'status' => $interpreted['category'] === 'auth' ? 'rejected' : 'not-verified',
                        'detail' => $interpreted['category'] === 'auth'
                            ? 'The device rejected the saved credentials.'
                            : 'The credentials could not be verified because the request failed first.',
                    ];
                }
            } else {
                $protocol['detail'] = 'Not tested because the device port did not accept a connection.';
                $authentication = [
                    'status' => 'not-verified',
                    'detail' => 'Authentication cannot be verified while the device is unreachable.',
                ];
            }
        }

        $sync = TerminalEventSync::query()->where('terminal_id', $terminal->id)->first();
        $recentErrors = DeviceActivityLog::query()
            ->where('terminal_id', $terminal->id)
            ->where('outcome', DeviceActivityLog::OUTCOME_FAILURE)
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (DeviceActivityLog $log): array => [
                'action' => $log->actionLabel(),
                'message' => $log->message,
                'at' => $this->label($log->created_at),
            ])
            ->all();

        $report = [
            'generated_at' => $this->label(now()),
            'device' => [
                'id' => $terminal->id,
                'name' => $terminal->name,
                'phase' => $terminal->phase,
                'location' => $terminal->location,
                'type' => $terminal->integrationLabel(),
                'model' => $terminal->model ?: ($deviceInfo['model'] ?? $deviceInfo['deviceName'] ?? null),
                'address' => "{$terminal->ip}:{$terminal->port}",
                'status' => $terminal->statusLabel(),
                'status_source' => $terminal->status_source,
            ],
            'network' => $reach === null
                ? ['tested' => false, 'reachable' => false, 'detail' => 'This reader has no remote network interface to check.']
                : [
                    'tested' => true,
                    'reachable' => $reach['reachable'],
                    'latency_ms' => $reach['latency_ms'],
                    'detail' => $reach['reachable']
                        ? "TCP connection to {$reach['address']} succeeded."
                        : "TCP connection to {$reach['address']} failed. {$reach['error']}",
                ],
            'protocol' => $protocol,
            'authentication' => $authentication,
            'history' => [
                'last_response_ms' => $terminal->last_latency_ms,
                'last_checked' => $this->label($terminal->last_checked_at),
                'last_successful_connection' => $this->label($terminal->last_seen_at),
                'failed_attempts' => (int) $terminal->consecutive_failures,
                'last_error' => $terminal->last_error,
                'last_error_at' => $this->label($terminal->last_error_at),
                'last_reconnect' => $this->label($terminal->last_reconnect_at),
            ],
            'gateway' => [
                'supported' => false,
                'detail' => 'This application connects directly to the device\'s ISAPI port. No separate gateway or controller is configured, so there is no gateway status to report.',
            ],
            'sync' => [
                'tested' => $terminal->supports('event_import'),
                'last_success' => $this->label($sync?->last_success_at),
                'last_attempt' => $this->label($sync?->last_attempt_at),
                'error' => $sync?->last_error,
                'detail' => $terminal->supports('event_import')
                    ? 'Automatic access-log collection uses the same device connection; a connectivity failure also stops collection until the device returns.'
                    : 'This device does not provide an access-log API.',
            ],
            'device_info' => $this->publicDeviceInfo($deviceInfo),
            'device_clock' => [
                'reported_local_time' => $deviceClock,
                'note' => 'Reported by the device in its own timezone. Drift is not calculated; compare it with the server time before relying on device timestamps.',
            ],
            'recent_errors' => $recentErrors,
            'recommendations' => [],
        ];

        $report['recommendations'] = $this->recommendations($terminal, $report, $sync);

        $this->activity->log(
            $terminal,
            $actor,
            'diagnostics',
            $report['protocol']['ok'] ? DeviceActivityLog::OUTCOME_SUCCESS : DeviceActivityLog::OUTCOME_FAILURE,
            $this->diagnosticSummary($report),
            ['network_reachable' => $report['network']['reachable'] ?? false],
            $ip,
        );

        return $report;
    }

    /** @param array<string, mixed> $report */
    private function diagnosticSummary(array $report): string
    {
        if ($report['protocol']['ok']) {
            return 'Diagnostics completed: the device answered an authenticated read-only request.';
        }

        if (! ($report['network']['reachable'] ?? false)) {
            return 'Diagnostics completed: the device could not be reached over the network.';
        }

        if (($report['authentication']['status'] ?? '') === 'rejected') {
            return 'Diagnostics completed: the device rejected its saved credentials.';
        }

        return 'Diagnostics completed: the device did not complete a read-only request.';
    }

    /**
     * Device metadata only: never a serial used as a credential, and never
     * anything the device reports under an authentication or password field.
     *
     * @param  array<string, mixed>  $info
     * @return array<string, string>
     */
    private function publicDeviceInfo(array $info): array
    {
        $allowed = ['deviceName', 'model', 'deviceType', 'firmwareVersion', 'firmwareReleasedDate', 'serialNumber', 'macAddress', 'deviceDescription'];
        $public = [];

        foreach ($allowed as $key) {
            if (isset($info[$key]) && is_scalar($info[$key]) && (string) $info[$key] !== '') {
                $public[$key] = (string) $info[$key];
            }
        }

        return $public;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function recommendations(Terminal $terminal, array $report, ?TerminalEventSync $sync): array
    {
        if (! $terminal->isApiManaged()) {
            return [
                'This reader has no remote API. Check it locally; nothing in this dashboard can query it.',
                'If this reader was replaced by a network terminal, update its integration type before expecting remote checks.',
            ];
        }

        $recommendations = [];

        if (! ($report['network']['reachable'] ?? false)) {
            $recommendations[] = 'Confirm the terminal is powered and its network cable or link is up.';
            $recommendations[] = "Confirm {$terminal->ip}:{$terminal->port} is correct; update the terminal if its address changed.";
            $recommendations[] = 'Check the VLAN, switch port and firewall rules between this server and the terminal.';
        } elseif (($report['authentication']['status'] ?? '') === 'rejected') {
            $recommendations[] = 'The network path works. Correct the device username and password in Terminals, then run Refresh.';
            $recommendations[] = 'The device itself is still enforcing its local access rules; door operation is unaffected by this dashboard error.';
        } elseif (! ($report['protocol']['ok'] ?? false)) {
            $recommendations[] = 'The device is reachable but did not complete a read. Confirm the model and firmware support ISAPI and that ISAPI is enabled.';
            $recommendations[] = 'Retry once; a busy terminal can refuse a request while it is writing a large update.';
        } else {
            $recommendations[] = 'No connectivity action is needed; the device answered an authenticated read.';
        }

        if ($sync?->last_error) {
            $recommendations[] = 'Access-log collection is reporting a failure: '.$sync->last_error;
        }

        if ($terminal->last_checked_at === null) {
            $recommendations[] = 'No background check has completed yet. Confirm `php artisan schedule:work` is running.';
        } elseif ($terminal->last_checked_at->lt(now()->subMinutes(10))) {
            $recommendations[] = 'Background checks are overdue. Confirm the scheduler and queue worker are running.';
        }

        return $recommendations;
    }

    private function label(?\DateTimeInterface $time): ?string
    {
        return $time === null
            ? null
            : Carbon::instance($time)->timezone(config('attendance.timezone'))->format('d M Y H:i:s');
    }
}
