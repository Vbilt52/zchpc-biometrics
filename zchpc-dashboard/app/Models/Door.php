<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical door enforced by one controller channel.
 *
 * Permissions are granted to doors; the terminal/door_no pair is what is
 * written to the device. A door without a controller cannot be deployed and is
 * shown as such rather than silently treated as protected.
 */
class Door extends Model
{
    protected $fillable = [
        'name',
        'code',
        'building',
        'floor',
        'zone',
        'terminal_id',
        'door_no',
        'plan_template_no',
        'notes',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'door_no' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function personnel(): BelongsToMany
    {
        return $this->belongsToMany(Personnel::class, 'personnel_door')
            ->withPivot(['plan_template_no', 'granted_by', 'granted_by_name', 'granted_at', 'expires_at', 'notes'])
            ->withTimestamps();
    }

    public function accessGroups(): BelongsToMany
    {
        return $this->belongsToMany(AccessGroup::class, 'access_group_doors')->withTimestamps();
    }

    public function grants(): HasMany
    {
        return $this->hasMany(PersonnelDoor::class);
    }

    /** @param Builder<Door> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Doors that can actually be enforced by a controller this application manages. */
    public function isDeployable(): bool
    {
        return $this->terminal !== null && $this->terminal->isApiManaged();
    }

    public function areaLabel(): string
    {
        $parts = array_filter([$this->building, $this->floor, $this->zone], fn ($value) => filled($value));

        return $parts === [] ? 'Ungrouped' : implode(' · ', $parts);
    }

    public function controllerLabel(): string
    {
        if (! $this->terminal) {
            return 'No controller';
        }

        return $this->terminal->name.' · channel '.$this->door_no;
    }

    public function statusLabel(): string
    {
        if (! $this->is_active) {
            return 'Out of service';
        }

        if (! $this->isDeployable()) {
            return 'No controller';
        }

        return $this->terminal->statusLabel();
    }

    public function statusTone(): string
    {
        if (! $this->is_active || ! $this->isDeployable()) {
            return 'muted';
        }

        return $this->terminal->statusTone();
    }
}
