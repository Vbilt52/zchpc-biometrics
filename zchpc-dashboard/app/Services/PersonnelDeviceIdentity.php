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
        $map = DB::table('personnel_device_aliases as aliases')
            ->join('personnel', 'personnel.id', '=', 'aliases.personnel_id')
            ->where('aliases.terminal_id', $terminal->id)
            ->select('personnel.employee_no as canonical_number', 'aliases.employee_no as device_number')
            ->get()->pluck('canonical_number', 'device_number')->all();
        foreach (DB::table('personnel_device_exclusions')->where('terminal_id', $terminal->id)->where('blocks_sync', true)->pluck('employee_no') as $number) {
            // A colon is disallowed in personnel IDs. This namespace cannot
            // accidentally join a different person's global legacy number.
            $map[$number] = self::unresolvedNumber($terminal, $number);
        }

        return $map;
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
        $numbers = array_values(array_unique(array_merge([$person->employee_no],
            DB::table('personnel_device_aliases')->where('personnel_id', $person->id)
                ->where('terminal_id', $terminal->id)->pluck('employee_no')->all())));
        foreach ($numbers as $number) {
            self::assertWritable($terminal, $number);
        }

        return $numbers;
    }

    public static function assertWritable(Terminal $terminal, string $number): void
    {
        if (DB::table('personnel_device_exclusions')->where('terminal_id', $terminal->id)
            ->where('employee_no', $number)->where('blocks_sync', true)->exists()) {
            throw new \RuntimeException('Device identity '.$number.' on '.$terminal->name.' has a name conflict. Review the identity before syncing or enrolling.');
        }
    }

    private static function unresolvedNumber(Terminal $terminal, string $number): string
    {
        return 'unresolved:'.$terminal->id.':'.$number;
    }

    /** Place a reviewed collision on hold without modifying the device. */
    public static function hold(Terminal $terminal, string $number, string $reason): void
    {
        DB::transaction(function () use ($terminal, $number, $reason): void {
            if (! preg_match('/^[A-Za-z0-9._-]{1,32}$/D', $number)
                || DB::table('personnel_device_aliases')->where('terminal_id', $terminal->id)->where('employee_no', $number)->exists()) {
                throw ValidationException::withMessages(['employee_no' => 'Review the existing identity link before placing this device ID on hold.']);
            }
            DB::table('personnel_device_exclusions')->updateOrInsert(['terminal_id' => $terminal->id, 'employee_no' => $number],
                ['blocks_sync' => true, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
            AccessEvent::where('terminal_id', $terminal->id)->where('employee_no', $number)->orderBy('id')->chunkById(200, function ($events) use ($terminal, $number): void {
                foreach ($events as $event) {
                    $event->update(['employee_no' => self::unresolvedNumber($terminal, $number),
                        'person_name' => $event->raw_payload['name'] ?? $event->raw_payload['employeeName'] ?? null]);
                }
            });
            app(AccessAuditLogger::class)->log('device_identity_held', 'warning',
                'Held a conflicting device identity for review; no device data was changed.',
                ['device_employee_no' => $number, 'reason' => $reason], terminal: $terminal);
        }, 5);
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
