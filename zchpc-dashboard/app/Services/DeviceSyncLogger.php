<?php

namespace App\Services;

use App\Models\DeviceSyncEvent;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\User;

/**
 * Synchronization history is written per controller, so "confirmed on two of
 * three doors" is visible rather than collapsed into one user-level flag.
 */
class DeviceSyncLogger
{
    public function record(
        ?Personnel $personnel,
        ?Terminal $terminal,
        string $operation,
        string $status,
        ?string $message = null,
        ?User $actor = null,
        ?string $employeeNo = null,
        ?string $personName = null,
    ): DeviceSyncEvent {
        $actor ??= $this->currentUser();

        return DeviceSyncEvent::query()->create([
            'personnel_id' => $personnel?->id,
            'employee_no' => $personnel?->employee_no ?? $employeeNo,
            'person_name' => $personnel?->name ?? $personName,
            'terminal_id' => $terminal?->id,
            'terminal_name' => $terminal?->name ?? 'Controller',
            'operation' => $operation,
            'status' => $status,
            'message' => $message,
            'actor_name' => $actor?->name ?? 'System',
            'created_at' => now(),
        ]);
    }

    private function currentUser(): ?User
    {
        try {
            return auth()->user();
        } catch (\Throwable) {
            return null;
        }
    }
}
