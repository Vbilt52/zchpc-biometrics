<?php

namespace App\Services;

use App\Models\AccessAuditLog;
use App\Models\Door;
use App\Models\Personnel;
use App\Models\Terminal;
use App\Models\User;

/**
 * Records identity and permission changes. Callers pass the human-readable
 * description; biometric material is never accepted by this logger.
 */
class AccessAuditLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function log(
        string $action,
        string $outcome = AccessAuditLog::OUTCOME_INFO,
        ?string $description = null,
        array $context = [],
        ?User $actor = null,
        ?Personnel $personnel = null,
        ?Door $door = null,
        ?Terminal $terminal = null,
        ?string $ipAddress = null,
        ?string $subjectLabel = null,
    ): AccessAuditLog {
        $actor ??= $this->currentUser();

        [$subjectType, $subjectId, $label] = match (true) {
            $personnel !== null => ['personnel', $personnel->id, $personnel->name],
            $door !== null => ['door', $door->id, $door->name],
            $terminal !== null => ['terminal', $terminal->id, $terminal->name],
            default => [null, null, null],
        };

        return AccessAuditLog::query()->create([
            'user_id' => $actor?->id,
            'user_name' => $actor?->name ?? 'System',
            'action' => $action,
            'outcome' => $outcome,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label' => $subjectLabel ?? $label,
            'door_id' => $door?->id,
            'terminal_id' => $terminal?->id,
            'description' => $description,
            'context' => $context === [] ? null : $context,
            'ip_address' => $ipAddress,
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
