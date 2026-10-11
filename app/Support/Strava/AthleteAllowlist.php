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
        return in_array($athleteId, $this->athleteIds(), strict: true);
    }

    /**
     * The allowlisted athlete ids, in the order they are configured.
     *
     * The order is meaningful: among connected allowlisted athletes, the first
     * is the one the public home page displays. With none connected, the home
     * page falls back to a stored athlete (ResolveDisplayedAthlete).
     *
     * @return array<int, string>
     */
    public function athleteIds(): array
    {
        /** @var array<int, string> $athleteIds */
        $athleteIds = config('strava.allowed_athlete_ids', []);

        return array_values($athleteIds);
    }
}
