<?php

namespace App\Services;

use App\Models\Terminal;
use App\Models\User;
use Shaykhnazar\HikvisionIsapi\Client\DeviceManager;

/**
 * Re-establishes this application's connection to a device.
 *
 * ISAPI is stateless per request, so "reconnect" means discarding the cached
 * client and saved-credential cache and authenticating again from scratch.
 * It never power-cycles, reboots or reconfigures the device. Remote restart is
 * deliberately not offered: the installed package has no documented reboot
 * call, and rebooting an access-control terminal could interrupt entry and
 * exit until it finishes booting.
 */
class DeviceConnectionService
{
    public function __construct(
        private readonly DeviceManager $deviceManager,
        private readonly DeviceHealthService $health,
        private readonly DeviceActivityLogger $activity,
    ) {}

    /**
     * @return array{ok: bool, message: string, status: string, latency_ms: int|null}
     */
    public function reconnect(Terminal $terminal, ?User $actor = null, ?string $ip = null): array
    {
        if (! $terminal->isApiManaged()) {
            return [
                'ok' => false,
                'status' => Terminal::STATUS_UNKNOWN,
                'latency_ms' => null,
                'message' => 'This reader has no remote API, so there is no connection to re-establish.',
            ];
        }

        $this->activity->log(
            $terminal,
            $actor,
            'reconnect',
            'info',
            'Reconnection requested: cached device clients were cleared and the saved credentials will be used again.',
            [],
            $ip,
        );

        // Drop cached clients and the Eloquent device provider's in-memory copy
        // so a corrected password or changed address is used immediately.
        $this->deviceManager->reload();

        $result = $this->health->check(
            $terminal,
            'reconnect',
            $actor,
            max(1, (int) config('devices.monitor.retry_attempts', 2)),
        );

        if ($result['ok']) {
            $terminal->forceFill(['last_reconnect_at' => now()])->save();
        }

        $this->activity->log(
            $terminal,
            $actor,
            'reconnect',
            $result['ok'] ? 'success' : 'failure',
            $result['ok']
                ? 'Reconnection succeeded: the device accepted a fresh authenticated request.'
                : 'Reconnection failed: '.$result['message'],
            ['status' => $result['status']],
            $ip,
        );

        return [
            'ok' => $result['ok'],
            'status' => $result['status'],
            'latency_ms' => $result['latency_ms'],
            'message' => $result['ok']
                ? 'Reconnected. The device accepted a fresh authenticated request in '.$result['latency_ms'].' ms.'
                : 'Reconnection failed: '.$result['message'],
        ];
    }
}
