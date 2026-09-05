<?php

namespace App\Support\Strava;

/**
 * The only access control in this product: the OAuth flow authenticates *a*
 * Strava athlete, this decides whether it is *the* athlete.
 */
class AthleteAllowlist
{
    /**
     * The comparison is strict on purpose. config/strava.php keeps only all-digit
     * strings, and a loose compare would let a value such as 0 match one of them.
     * An empty allowlist therefore rejects everyone — the gate fails closed.
     */
    public function allows(string $athleteId): bool
    {
        /** @var array<int, string> $allowed */
        $allowed = config('strava.allowed_athlete_ids', []);

        return in_array($athleteId, $allowed, strict: true);
    }
}
