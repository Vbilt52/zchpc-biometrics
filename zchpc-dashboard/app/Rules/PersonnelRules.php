<?php

namespace App\Rules;

use Illuminate\Validation\Rule;

class PersonnelRules
{
    public static function name(): array
    {
        return ['required', 'string', 'max:64', 'regex:/[\p{L}]/u', 'not_regex:/[\x00-\x1F\x7F<>]/u'];
    }

    public static function employeeNo(?int $ignoreId = null): array
    {
        // Archived identities remain reserved to protect device credentials and history.
        return ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('personnel', 'employee_no')->ignore($ignoreId)];
    }

    public static function phone(): array
    {
        return ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9][0-9 ()-]{5,30}$/'];
    }

    public static function begin(): array
    {
        return ['required', 'date', 'after_or_equal:2000-01-01', 'before:2038-01-01'];
    }

    public static function end(): array
    {
        return ['required', 'date', 'after:begin_time', 'before:2038-01-01'];
    }
}
