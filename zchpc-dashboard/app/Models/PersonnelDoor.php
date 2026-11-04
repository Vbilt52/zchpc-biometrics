<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's grant of one door, with its own grant and expiry metadata. */
class PersonnelDoor extends Model
{
    protected $table = 'personnel_door';

    protected $fillable = [
        'personnel_id',
        'door_id',
        'plan_template_no',
        'granted_by',
        'granted_by_name',
        'granted_at',
        'expires_at',
        'notes',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    public function door(): BelongsTo
    {
        return $this->belongsTo(Door::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
