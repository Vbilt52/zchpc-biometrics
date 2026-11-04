<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks super-fingerprint enrollment requests without ever storing biometric
 * templates. The template stays exclusively on the physical terminal.
 */
class TerminalSuperFingerprint extends Model
{
    protected $fillable = [
        'terminal_id',
        'personnel_id',
        'finger_id',
        'status',
        'last_response',
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
