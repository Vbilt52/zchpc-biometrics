<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceAlert extends Model
{
    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    protected $guarded = ['id'];

    protected $casts = [
        'occurrences' => 'integer',
        'first_occurred_at' => 'datetime',
        'last_occurred_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'metadata' => 'array',
    ];

    /** @return BelongsTo<Terminal, $this> */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /** @param Builder<DeviceAlert> $query */
    public function scopeUnacknowledged(Builder $query): void
    {
        $query->whereNull('acknowledged_at');
    }

    public function severityLabel(): string
    {
        return ucfirst($this->severity);
    }

    public function tone(): string
    {
        return match ($this->severity) {
            self::SEVERITY_CRITICAL => 'danger',
            self::SEVERITY_WARNING => 'warning',
            default => 'primary',
        };
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'device_offline' => 'Device offline',
            'device_online' => 'Device restored',
            'authentication_failure' => 'Connection authentication failure',
            'communication_failure' => 'Communication failure',
            'repeated_failures' => 'Repeated communication failures',
            'sync_failure' => 'Synchronization failure',
            default => ucfirst(str_replace('_', ' ', $this->type)),
        };
    }
}
