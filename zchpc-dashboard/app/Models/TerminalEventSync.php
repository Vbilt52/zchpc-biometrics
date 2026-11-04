<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TerminalEventSync extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime',
        'through_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'last_success_at' => 'datetime',
    ];
}
