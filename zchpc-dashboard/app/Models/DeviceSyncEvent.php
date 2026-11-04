<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One attempt to write or revoke a person's credential on one controller. */
class DeviceSyncEvent extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_QUEUED = 'queued';

    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function operationLabel(): string
    {
        return match ($this->operation) {
            'grant' => 'Grant / update',
            'revoke' => 'Revoke',
            'delete' => 'Delete credential',
            default => ucfirst($this->operation),
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_SUCCESS => 'Confirmed',
            self::STATUS_FAILED => 'Failed',
            default => 'Queued',
        };
    }

    public function tone(): string
    {
        return match ($this->status) {
            self::STATUS_SUCCESS => 'success',
            self::STATUS_FAILED => 'danger',
            default => 'warning',
        };
    }
}
