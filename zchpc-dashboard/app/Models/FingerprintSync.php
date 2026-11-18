<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Queue metadata only; never contains biometric templates or their hashes. */
class FingerprintSync extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['confirmed_slots' => 'array', 'next_attempt_at' => 'datetime', 'confirmed_at' => 'datetime'];

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class)->withTrashed();
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function sourceTerminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class, 'source_terminal_id');
    }
}
