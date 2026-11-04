<?php

namespace App\Services;

use App\Models\DeviceAlert;
use App\Models\DeviceMonitorSettings;
use App\Models\Terminal;

/**
 * Creates in-app alerts for device conditions.
 *
 * Alerts are deduplicated: while an unacknowledged alert of the same type for
 * the same device is inside the cooldown window, a further occurrence bumps
 * its counter and last-occurrence time instead of creating another row. This
 * is what keeps a periodically monitored offline device from producing one
 * alert per minute.
 */
class DeviceAlertService
{
    /**
     * Record (or refresh) an alert.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        ?Terminal $terminal,
        string $type,
        string $severity,
        string $title,
        ?string $message = null,
        array $metadata = [],
    ): DeviceAlert {
        $settings = DeviceMonitorSettings::current();
        $cooldown = max(1, $settings->alert_cooldown_minutes);
        $since = now()->subMinutes($cooldown);

        $existing = DeviceAlert::query()
            ->where('type', $type)
            ->when(
                $terminal === null,
                fn ($query) => $query->whereNull('terminal_id'),
                fn ($query) => $query->where('terminal_id', $terminal->id),
            )
            ->whereNull('acknowledged_at')
            ->where('last_occurred_at', '>=', $since)
            ->latest('last_occurred_at')
            ->first();

        if ($existing !== null) {
            $existing->forceFill([
                'occurrences' => $existing->occurrences + 1,
                'last_occurred_at' => now(),
                'message' => $message,
                'metadata' => $metadata === [] ? $existing->metadata : $metadata,
            ])->save();

            return $existing;
        }

        return DeviceAlert::query()->create([
            'terminal_id' => $terminal?->id,
            'terminal_name' => $terminal?->name,
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'occurrences' => 1,
            'first_occurred_at' => now(),
            'last_occurred_at' => now(),
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    /**
     * Decide which alerts a completed connectivity check should raise.
     *
     * @param  array{ok: bool, status: string, category: string, message: string, latency_ms: int|null}  $outcome
     */
    public function deviceChecked(Terminal $terminal, string $previousStatus, array $outcome): void
    {
        if ($outcome['ok']) {
            if (in_array($previousStatus, [Terminal::STATUS_OFFLINE, Terminal::STATUS_ERROR], true)) {
                $this->record(
                    $terminal,
                    'device_online',
                    DeviceAlert::SEVERITY_INFO,
                    'Device restored',
                    "{$terminal->name} is answering authenticated requests again after being {$previousStatus}.",
                    ['previous_status' => $previousStatus],
                );
            }

            return;
        }

        if ($outcome['status'] === Terminal::STATUS_OFFLINE) {
            $this->record(
                $terminal,
                'device_offline',
                DeviceAlert::SEVERITY_CRITICAL,
                'Device offline',
                "{$terminal->name} could not be reached. {$outcome['message']}",
                ['category' => $outcome['category']],
            );
        } elseif ($outcome['category'] === 'auth') {
            $this->record(
                $terminal,
                'authentication_failure',
                DeviceAlert::SEVERITY_CRITICAL,
                'Connection authentication failure',
                "{$terminal->name} answered but rejected its saved credentials. Access control on the device is unaffected, but the dashboard cannot read or manage it until the credentials are corrected.",
                ['category' => $outcome['category']],
            );
        } else {
            $this->record(
                $terminal,
                'communication_failure',
                DeviceAlert::SEVERITY_WARNING,
                'Device communication failure',
                "{$terminal->name} did not complete a status read. {$outcome['message']}",
                ['category' => $outcome['category']],
            );
        }

        $threshold = max(1, (int) config('devices.monitor.failures_before_alert', 3));

        if ($terminal->consecutive_failures >= $threshold) {
            $this->record(
                $terminal,
                'repeated_failures',
                DeviceAlert::SEVERITY_WARNING,
                'Repeated communication failures',
                "{$terminal->name} has failed {$terminal->consecutive_failures} consecutive checks. Check power, cabling, the network path and the saved credentials.",
                ['consecutive_failures' => $terminal->consecutive_failures],
            );
        }
    }

    /** Log collection failures are separate from connectivity failures. */
    public function syncFailed(Terminal $terminal, string $message): void
    {
        $this->record(
            $terminal,
            'sync_failure',
            DeviceAlert::SEVERITY_WARNING,
            'Access-log synchronization failing',
            "{$terminal->name} is not completing automatic access-log collection. {$message}",
        );
    }
}
