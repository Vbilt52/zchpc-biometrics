<?php

namespace App\Services;

use App\Models\DeviceActivityLog;
use App\Models\Terminal;
use App\Models\User;

/**
 * Append-only record of device-management actions. Names and addresses are
 * snapshotted so history survives a terminal or account being removed.
 */
class DeviceActivityLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function log(
        ?Terminal $terminal,
        ?User $actor,
        string $action,
        string $outcome = DeviceActivityLog::OUTCOME_INFO,
        ?string $message = null,
        array $context = [],
        ?string $ipAddress = null,
    ): DeviceActivityLog {
        return DeviceActivityLog::query()->create([
            'terminal_id' => $terminal?->id,
            'terminal_name' => $terminal?->name,
            'user_id' => $actor?->id,
            'user_name' => $actor?->name ?? 'System',
            'action' => $action,
            'outcome' => $outcome,
            'message' => $message,
            'context' => $context === [] ? null : $context,
            'ip_address' => $ipAddress,
            'created_at' => now(),
        ]);
    }

    /** Request IP, or null when the action came from the scheduler/CLI. */
    public static function requestIp(): ?string
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        try {
            return request()->ip();
        } catch (\Throwable) {
            return null;
        }
    }
}
