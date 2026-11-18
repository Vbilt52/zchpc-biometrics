<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PersonnelNumbering
{
    public static function format(int $number): string
    {
        return 'HPC'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }

    public static function preview(): string
    {
        $number = (int) DB::table('personnel_number_sequences')->where('id', 1)->value('last_number');
        do {
            $code = self::format(++$number);
        } while (DB::table('personnel')->where('employee_no', $code)->orWhere('personnel_code', $code)->exists()
            || DB::table('personnel_device_aliases')->where('employee_no', $code)->exists());

        return $code;
    }

    /** Called inside the personnel insert transaction; the write serializes allocators. */
    public static function allocate(): string
    {
        do {
            DB::table('personnel_number_sequences')->where('id', 1)->increment('last_number');
            $code = self::format((int) DB::table('personnel_number_sequences')->where('id', 1)->value('last_number'));
        } while (DB::table('personnel')->where('employee_no', $code)->orWhere('personnel_code', $code)->exists()
            || DB::table('personnel_device_aliases')->where('employee_no', $code)->exists());

        return $code;
    }
}
