<?php

namespace App\Support;

use DateTimeZone;

/**
 * Picks the timezone whose reference city is closest to a shared location, from PHP's bundled tz database.
 */
final class NearestTimezone
{
    public static function find(float $latitude, float $longitude): ?string
    {
        $best = null;
        $bestDistance = INF;

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $location = (new DateTimeZone($identifier))->getLocation();
            if ($location === false || ($location['country_code'] ?? '??') === '??') {
                continue;
            }

            $distance = self::distanceKm($latitude, $longitude, (float) $location['latitude'], (float) $location['longitude']);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $identifier;
            }
        }

        return $best;
    }

    private static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
