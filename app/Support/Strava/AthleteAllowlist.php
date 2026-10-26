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
     * The allowlist decides which Strava athlete may be attached to the owner and
     * which connected athletes the nightly sync reads. The order carries no
     * meaning any more: no screen picks a displayed athlete from it.
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
