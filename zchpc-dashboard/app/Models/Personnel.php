<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Personnel extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_DRAFT = 'draft';

    protected $table = 'personnel';

    /** Existing rows and programmatic creates behave as active people. */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected $fillable = [
        'employee_no',
        'name',
        'user_type',
        'valid_enabled',
        'begin_time',
        'end_time',
        'door_right',
        'right_plan',
        'phone_number',
        'department',
        'access_group_id',
        'email',
        'notes',
        'photo_path',
        'status',
        'added_at',
    ];

    protected $casts = [
        'valid_enabled' => 'boolean',
        'begin_time' => 'datetime',
        'end_time' => 'datetime',
        'right_plan' => 'array',
        'added_at' => 'datetime',
    ];

    /**
     * Terminals this person has been synced to.
     */
    public function terminals(): BelongsToMany
    {
        return $this->belongsToMany(Terminal::class, 'personnel_terminals')
            ->withPivot('synced_at', 'access_allowed', 'sync_status')
            ->withTimestamps();
    }

    /**
     * Managed department / access group for this person.
     */
    public function accessGroup(): BelongsTo
    {
        return $this->belongsTo(AccessGroup::class);
    }

    /** Doors explicitly granted to this person. */
    public function doors(): BelongsToMany
    {
        return $this->belongsToMany(Door::class, 'personnel_door')
            ->withPivot(['plan_template_no', 'granted_by', 'granted_by_name', 'granted_at', 'expires_at', 'notes'])
            ->withTimestamps();
    }

    public function doorGrants(): HasMany
    {
        return $this->hasMany(PersonnelDoor::class);
    }

    public function fingerprintEnrollments(): HasMany
    {
        return $this->hasMany(FingerprintEnrollment::class);
    }

    public function syncEvents(): HasMany
    {
        return $this->hasMany(DeviceSyncEvent::class);
    }

    public function pendingSyncs(): HasMany
    {
        return $this->hasMany(PendingTerminalSync::class);
    }

    public function accessEvents(): HasMany
    {
        return $this->hasMany(AccessEvent::class, 'employee_no', 'employee_no');
    }

    /** @param Builder<Personnel> $query */
    public function scopeOnboarded(Builder $query): void
    {
        $query->where('status', '!=', self::STATUS_DRAFT);
    }

    /**
     * Doors that are currently enforceable: grant not expired, door in service.
     *
     * @return Collection<int, Door>
     */
    public function effectiveDoors(): Collection
    {
        return $this->doors()
            ->where('doors.is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('personnel_door.expires_at')
                    ->orWhere('personnel_door.expires_at', '>', now());
            })
            ->orderBy('doors.sort_order')
            ->orderBy('doors.name')
            ->get();
    }

    /** True when this person has ever been granted door permissions. */
    public function hasDoorGrants(): bool
    {
        return $this->doors()->exists();
    }

    /**
     * Device `doorRight` for one controller.
     *
     * Door grants are authoritative as soon as a person has any. For people who
     * have never been edited through the access module, the legacy channel
     * columns are returned unchanged so existing behaviour is preserved.
     */
    public function doorRightFor(?Terminal $terminal): string
    {
        if (! $this->hasDoorGrants()) {
            return (string) ($this->door_right ?: '1');
        }

        if ($terminal === null) {
            return '';
        }

        return $this->effectiveDoors()
            ->where('terminal_id', $terminal->id)
            ->pluck('door_no')
            ->unique()
            ->sort()
            ->implode(',');
    }

    /**
     * Device `RightPlan` for one controller, derived from granted doors.
     *
     * @return list<array{doorNo: int, planTemplateNo: string}>
     */
    public function rightPlanFor(?Terminal $terminal): array
    {
        if (! $this->hasDoorGrants()) {
            return $this->right_plan ?: [['doorNo' => 1, 'planTemplateNo' => '1']];
        }

        if ($terminal === null) {
            return [];
        }

        return $this->effectiveDoors()
            ->where('terminal_id', $terminal->id)
            ->unique('door_no')
            ->sortBy('door_no')
            ->map(fn (Door $door): array => [
                'doorNo' => (int) $door->door_no,
                'planTemplateNo' => (string) ($door->pivot->plan_template_no ?: $door->plan_template_no ?: '1'),
            ])
            ->values()
            ->all();
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isBlocklisted(): bool
    {
        return $this->user_type === 'blocklist';
    }

    /**
     * The state an administrator sees, which is not only "valid or not":
     * a suspended account, a future-dated window and an expired window all
     * need different wording.
     */
    public function accessState(): string
    {
        if ($this->isDraft()) {
            return 'draft';
        }

        if ($this->isSuspended()) {
            return 'suspended';
        }

        if ($this->isBlocklisted()) {
            return 'blocked';
        }

        if (! $this->valid_enabled) {
            return 'disabled';
        }

        $now = now();

        if ($this->begin_time && $now->lt($this->begin_time)) {
            return 'scheduled';
        }

        if ($this->end_time && $now->gt($this->end_time)) {
            return 'expired';
        }

        return 'active';
    }

    public function accessStateLabel(): string
    {
        return match ($this->accessState()) {
            'draft' => 'Draft onboarding',
            'suspended' => 'Suspended',
            'blocked' => 'Blocked',
            'disabled' => 'Disabled',
            'scheduled' => 'Starts later',
            'expired' => 'Expired',
            default => 'Active',
        };
    }

    public function accessStateTone(): string
    {
        return match ($this->accessState()) {
            'active' => 'success',
            'scheduled', 'draft' => 'warning',
            'suspended', 'blocked', 'expired' => 'danger',
            default => 'muted',
        };
    }

    /** The controller's own last confirmed instruction for this person. */
    public function controllerSyncStatus(?int $terminalId): ?string
    {
        if ($terminalId === null) {
            return null;
        }

        $terminal = $this->relationLoaded('terminals')
            ? $this->terminals->firstWhere('id', $terminalId)
            : $this->terminals()->whereKey($terminalId)->first();

        if ($terminal === null) {
            return 'not_deployed';
        }

        if (! (bool) $terminal->pivot->access_allowed) {
            return 'revoked';
        }

        return (string) ($terminal->pivot->sync_status ?: 'pending');
    }

    public function hasConfirmedFingerprint(): bool
    {
        if ($this->relationLoaded('fingerprintEnrollments')) {
            return $this->fingerprintEnrollments->contains('status', FingerprintEnrollment::STATUS_CONFIRMED);
        }

        return $this->fingerprintEnrollments()
            ->where('status', FingerprintEnrollment::STATUS_CONFIRMED)
            ->exists();
    }

    public function confirmedFingerprints(): Collection
    {
        return ($this->relationLoaded('fingerprintEnrollments')
            ? $this->fingerprintEnrollments
            : $this->fingerprintEnrollments()->get())
            ->where('status', FingerprintEnrollment::STATUS_CONFIRMED)
            ->sortBy('finger_id')
            ->values();
    }

    /** Pending revocation or grant rows on controllers, for the warning banner. */
    public function unconfirmedControllers(): Collection
    {
        $terminals = $this->relationLoaded('terminals') ? $this->terminals : $this->terminals()->get();

        return $terminals
            ->filter(fn (Terminal $terminal): bool => $terminal->pivot->sync_status !== 'confirmed')
            ->values();
    }

    public function roleLabel(): string
    {
        return match ($this->user_type) {
            'visitor' => 'Visitor',
            'blocklist' => 'Blocklist',
            default => 'Normal User',
        };
    }

    public function roleBadgeClass(): string
    {
        return match ($this->user_type) {
            'visitor' => 'badge-warning',
            'blocklist' => 'badge-danger',
            default => 'badge-primary',
        };
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials !== '' ? $initials : '?';
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? route('access.users.photo', $this) : null;
    }

    /**
     * Whether this person's access is currently active (valid and within time range).
     */
    public function isActive(): bool
    {
        if ($this->isDraft() || $this->isSuspended()) {
            return false;
        }

        if (! $this->valid_enabled || $this->user_type === 'blocklist') {
            return false;
        }
        $now = now();
        if ($this->begin_time && $now->lt($this->begin_time)) {
            return false;
        }
        if ($this->end_time && $now->gt($this->end_time)) {
            return false;
        }

        return true;
    }
}
