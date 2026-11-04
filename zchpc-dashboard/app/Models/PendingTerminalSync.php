<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingTerminalSync extends Model
{
    protected $fillable = [
        'terminal_id',
        'personnel_id',
        'operation',
        'attempts',
        'last_error',
        'last_attempt_at',
        'next_attempt_at',
    ];

    protected $casts = [
        'last_attempt_at' => 'datetime',
        'next_attempt_at' => 'datetime',
    ];

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }
}
