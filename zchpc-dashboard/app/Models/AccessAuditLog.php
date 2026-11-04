<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail for identity, permission and enrollment changes.
 *
 * Names and labels are snapshotted so the record survives the person or
 * account being removed. Entries never contain biometric templates.
 */
class AccessAuditLog extends Model
{
    public const UPDATED_AT = null;

    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_FAILURE = 'failure';

    public const OUTCOME_INFO = 'info';

    public const OUTCOME_WARNING = 'warning';

    protected $guarded = ['id'];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function door(): BelongsTo
    {
        return $this->belongsTo(Door::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            'user_created' => 'Person created',
            'user_updated' => 'Person details changed',
            'user_onboarded' => 'Access onboarding completed',
            'permission_granted' => 'Door access granted',
            'permission_revoked' => 'Door access revoked',
            'permission_expired' => 'Door access expired',
            'permission_suspended' => 'Access suspended',
            'permission_reactivated' => 'Access reactivated',
            'user_deleted' => 'Person deleted',
            'draft_discarded' => 'Draft onboarding discarded',
            'enrollment_started' => 'Fingerprint enrollment started',
            'enrollment_confirmed' => 'Fingerprint enrollment confirmed',
            'enrollment_failed' => 'Fingerprint enrollment failed',
            'enrollment_cancelled' => 'Fingerprint enrollment cancelled',
            'enrollment_skipped' => 'Fingerprint enrollment skipped',
            'credential_sync' => 'Credential synchronization',
            'door_created' => 'Door registered',
            'door_updated' => 'Door details changed',
            'door_removed' => 'Door removed',
            'capability_probe' => 'Device capability checked',
            default => ucfirst(str_replace('_', ' ', $this->action)),
        };
    }

    public function tone(): string
    {
        return match ($this->outcome) {
            self::OUTCOME_SUCCESS => 'success',
            self::OUTCOME_FAILURE => 'danger',
            self::OUTCOME_WARNING => 'warning',
            default => 'primary',
        };
    }
}
