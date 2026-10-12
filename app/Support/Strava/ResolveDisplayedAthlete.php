<?php

namespace App\Support\Strava;

use App\Models\User;

/**
 * Decides whose heatmap a signed-out visitor sees on the public home page: the
 * first allowlisted athlete, in configured order, who has connected Strava —
 * otherwise the stored athlete with the lowest id who has at least one
 * activity, so stored data is shown rather than the connect screen.
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
            return $this->firstStoredAthleteWithActivities();
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
            ->select(['id', 'strava_athlete_id'])
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

        return $this->firstStoredAthleteWithActivities();
    }

    private function firstStoredAthleteWithActivities(): ?User
    {
        /*
         * The fallback runs only after the allowlist walk found nobody, so a
         * connected allowlisted athlete always wins. Without it the page would
         * show the connect screen although runs are stored (FR-017).
         *
         * The lowest users.id is a stable tie-break: ordering by the latest
         * activity would flip the displayed athlete whenever someone runs. The
         * select is as narrow as the allowlist query's, for the same reason —
         * no guest request may load a token. The owner accepted that this can
         * display an athlete removed from the allowlist (PRD "Accepted risk —
         * fallback display").
         */
        return User::query()
            ->select(['id', 'strava_athlete_id'])
            ->whereHas('activities')
            ->orderBy('id')
            ->first();
    }
}
