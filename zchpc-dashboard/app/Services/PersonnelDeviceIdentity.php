<?php

namespace App\Services;

use App\Models\AccessEvent;
use App\Models\Personnel;
use App\Models\Terminal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Explicitly reviewed additional IDs; never match a person's name automatically. */
class PersonnelDeviceIdentity
{
    public static function map(Terminal $terminal): array
    {
        return DB::table('personnel_device_aliases as aliases')
            ->join('personnel', 'personnel.id', '=', 'aliases.personnel_id')
            ->where('aliases.terminal_id', $terminal->id)
            ->select('personnel.employee_no as canonical_number', 'aliases.employee_no as device_number')
            ->get()->pluck('canonical_number', 'device_number')->all();
    }

    public static function excluded(Terminal $terminal, string $employeeNo): bool
    {
        return DB::table('personnel_device_exclusions')->where('terminal_id', $terminal->id)
            ->where('employee_no', $employeeNo)->exists();
    }

    public static function resolve(Terminal $terminal, string $employeeNo): ?Personnel
    {
        $canonical = self::map($terminal)[$employeeNo] ?? $employeeNo;

        return Personnel::withTrashed()->where('employee_no', $canonical)->first();
    }

    public static function numbers(Personnel $person, Terminal $terminal): array
    {
        return array_values(array_unique(array_merge([$person->employee_no],
            DB::table('personnel_device_aliases')->where('personnel_id', $person->id)
                ->where('terminal_id', $terminal->id)->pluck('employee_no')->all())));
    }

    /** Link only after an operator has confirmed identity; no device access is granted. */
    public static function link(Personnel $person, Terminal $terminal, string $employeeNo): void
    {
        DB::transaction(function () use ($person, $terminal, $employeeNo): void {
            $person->refresh();
            $collision = Personnel::withTrashed()->where('employee_no', $employeeNo)->exists();
            $owner = DB::table('personnel_device_aliases')->where('terminal_id', $terminal->id)
                ->where('employee_no', $employeeNo)->value('personnel_id');
            if ($person->trashed() || $collision || self::excluded($terminal, $employeeNo) || ($owner !== null && (int) $owner !== $person->id)
                || ! preg_match('/^[A-Za-z0-9._-]{1,32}$/D', $employeeNo)) {
                throw ValidationException::withMessages(['employee_no' => 'Device identity is reserved or conflicts with another person.']);
            }
            if ($owner === null) {
                DB::table('personnel_device_aliases')->insert([
                    'personnel_id' => $person->id, 'terminal_id' => $terminal->id,
                    'employee_no' => $employeeNo, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            // Only the normalized link changes. Original event payload and event key remain intact.
            AccessEvent::where('terminal_id', $terminal->id)->where('employee_no', $employeeNo)
                ->update(['employee_no' => $person->employee_no]);
            app(AccessAuditLogger::class)->log('device_identity_linked', 'success',
                'Linked an operator-confirmed additional device identity to existing personnel.',
                ['device_employee_no' => $employeeNo, 'employee_id' => $person->employee_id],
                personnel: $person, terminal: $terminal);
        }, 5);
    }
}
