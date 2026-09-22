<?php

namespace App\Support;

class TimezoneCatalog
{
    public static function resolve(string $input): ?string
    {
        $trimmed = trim($input);

        if (in_array($trimmed, timezone_identifiers_list(), true)) {
            return $trimmed;
        }

        $aliases = config('adhkar.timezone_aliases', []);
        if (isset($aliases[$trimmed])) {
            return $aliases[$trimmed];
        }

        $normalized = mb_strtolower($trimmed);
        foreach (timezone_identifiers_list() as $id) {
            if (mb_strtolower($id) === $normalized) {
                return $id;
            }
            if (mb_strtolower(str_replace('_', ' ', $id)) === $normalized) {
                return $id;
            }
            $city = mb_strtolower((string) last(explode('/', $id)));
            if ($city === $normalized) {
                return $id;
            }
        }

        return null;
    }
}
