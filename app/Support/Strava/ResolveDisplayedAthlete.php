<?php

namespace App\Support\Strava;

use App\Models\User;

/**
 * Decides whose heatmap a signed-out visitor sees on the public home page: the
 * first allowlisted athlete, in configured order, who has connected Strava.
 *
 * Read-only by construction — it never loads, decrypts, or refreshes a token.
 */
final readonly class ResolveDisplayedAthlete
{
    public function __construct(
        private AthleteAllowlist $allowlist,
    ) {}

    public function __invoke(): ?User
    {
        $athleteIds = $this->allowlist->athleteIds();

        if ($athleteIds === []) {
            return null;
        }

        /*
         * Only the columns the dashboard reads. The token columns are never
         * selected, so no guest request can decrypt one — or hand one to
         * EnsureFreshAccessToken by accident — and an undecryptable token pair
         * (a rotated APP_KEY) cannot turn the public page into a 500.
         *
         * Token state is ignored on purpose: an owner whose refresh token is
         * dead is still the displayed athlete. Their last synced map stays
         * public while the reconnect banner asks them to connect again.
         */
        $connectedAthletes = User::query()
            ->select(['id', 'name', 'strava_athlete_id'])
            ->whereIn('strava_athlete_id', $athleteIds)
            ->get()
            ->keyBy('strava_athlete_id');

        /*
         * The order comes from the allowlist, not from the database: it is the
         * one place the owner states who comes first, and ordering by users.id
         * would display whoever happened to connect first instead.
         */
        foreach ($athleteIds as $athleteId) {
            $athlete = $connectedAthletes->get($athleteId);

            if ($athlete instanceof User) {
                return $athlete;
            }
        }

        return null;
    }
}
