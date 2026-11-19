<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Metadata observed on a terminal; no biometric material is stored. */
class FingerprintInventory extends Model
{
    protected $fillable = ['personnel_id', 'terminal_id', 'device_employee_no', 'slots', 'checked_at', 'last_error'];

    protected $casts = ['slots' => 'array', 'checked_at' => 'datetime'];

    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /** @return list<int> */
    public function slotNumbers(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $this->slots ?? []), fn (int $slot) => $slot >= 1 && $slot <= 10)));
    }
}
