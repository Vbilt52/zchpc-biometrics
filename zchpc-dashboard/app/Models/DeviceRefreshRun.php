<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceRefreshRun extends Model
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_OFFLINE = 'offline';

    public const SCOPE_SELECTED = 'selected';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    protected $guarded = ['id'];

    protected $casts = [
        'total' => 'integer',
        'completed' => 'integer',
        'failed' => 'integer',
        'cancelled' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** @return HasMany<DeviceRefreshItem> */
    public function items(): HasMany
    {
        return $this->hasMany(DeviceRefreshItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING]);
    }

    public function pendingCount(): int
    {
        return max(0, $this->total - $this->completed - $this->cancelled);
    }

    /** @return array<string, int|string> */
    public function progress(): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope,
            'status' => $this->status,
            'total' => $this->total,
            'completed' => $this->completed,
            'failed' => $this->failed,
            'cancelled' => $this->cancelled,
            'pending' => $this->pendingCount(),
            'message' => $this->message,
            'age_seconds' => $this->started_at?->diffInSeconds(now()) ?? 0,
        ];
    }
}
