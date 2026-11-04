<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccessGroup extends Model
{
    protected $fillable = ['name'];

    public function personnel(): HasMany
    {
        return $this->hasMany(Personnel::class);
    }

    /** Doors this group grants when applied to a person. */
    public function doors(): BelongsToMany
    {
        return $this->belongsToMany(Door::class, 'access_group_doors')->withTimestamps();
    }
}
