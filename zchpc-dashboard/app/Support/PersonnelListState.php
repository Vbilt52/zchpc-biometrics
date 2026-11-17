<?php

namespace App\Support;

use Illuminate\Http\Request;

class PersonnelListState
{
    /** Return only known scalar filters. Never accept a redirect URL. */
    public static function query(Request $request, bool $fromList = false): array
    {
        $input = $fromList ? $request->query() : $request->input('list', []);
        if (! is_array($input) || $input === []) {
            return [];
        }
        $query = [];
        foreach (['page', 'search', 'role', 'terminal_id', 'access_group'] as $key) {
            if (isset($input[$key]) && is_scalar($input[$key]) && strlen((string) $input[$key]) <= 128) {
                $query[$key] = (string) $input[$key];
            }
        }
        $query['page'] = max(1, min(100000, (int) ($query['page'] ?? 1)));

        return $query;
    }

    public static function url(Request $request): string
    {
        return route('personnel.index', self::query($request));
    }
}
