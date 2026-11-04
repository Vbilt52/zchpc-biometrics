<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceCheckout extends Model
{
    // Each correction creates another row, preserving earlier operator records.
    protected $fillable = [
        'terminal_id', 'employee_no', 'attendance_date', 'checked_out_at',
        'last_entry_at', 'person_name', 'terminal_name', 'recorded_by',
        'recorded_by_name', 'reason',
    ];

    protected $casts = [
        // Keep the local day as a date-only string, including on SQLite.
        'attendance_date' => 'string',
        'checked_out_at' => 'immutable_datetime',
        'last_entry_at' => 'immutable_datetime',
    ];
}
