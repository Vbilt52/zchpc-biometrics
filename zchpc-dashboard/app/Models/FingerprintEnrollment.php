<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempted fingerprint capture on one terminal.
 *
 * The device owns the template; this row records only who asked, which finger,
 * what the terminal replied, and whether the slot was confirmed afterwards.
 * `device_response` is a redacted status summary, never fingerData.
 */
class FingerprintEnrollment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_TIMED_OUT = 'timed_out';

    protected $fillable = [
        'personnel_id',
        'employee_no',
        'person_name',
        'terminal_id',
        'terminal_name',
        'finger_id',
        'status',
        'message',
        'device_response',
        'requested_by',
        'requested_by_name',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'finger_id' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /** @param Builder<FingerprintEnrollment> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_WAITING]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_WAITING], true);
    }

    public function isFinished(): bool
    {
        return ! $this->isActive();
    }

    public function label(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Queued for the terminal',
            self::STATUS_WAITING => 'Waiting for finger',
            self::STATUS_CONFIRMED => 'Enrollment confirmed',
            self::STATUS_CANCELLED => 'Cancelled',
            self::STATUS_TIMED_OUT => 'Timed out',
            default => 'Enrollment failed',
        };
    }

    public function tone(): string
    {
        return match ($this->status) {
            self::STATUS_CONFIRMED => 'success',
            self::STATUS_FAILED, self::STATUS_TIMED_OUT => 'danger',
            self::STATUS_CANCELLED => 'muted',
            default => 'primary',
        };
    }

    public static function fingerLabel(int $fingerId): string
    {
        return match ($fingerId) {
            1 => 'Finger 1 · right thumb',
            2 => 'Finger 2 · right index',
            3 => 'Finger 3 · right middle',
            4 => 'Finger 4 · right ring',
            5 => 'Finger 5 · right little',
            6 => 'Finger 6 · left thumb',
            7 => 'Finger 7 · left index',
            8 => 'Finger 8 · left middle',
            9 => 'Finger 9 · left ring',
            10 => 'Finger 10 · left little',
            default => 'Finger '.$fingerId,
        };
    }

    public function fingerLabelText(): string
    {
        return self::fingerLabel((int) $this->finger_id);
    }
}
