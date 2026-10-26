<?php

namespace App\Data\Garmin;

use App\Models\Activity;

/**
 * Every parsed row sorted against the stored activities, before anything is
 * written. Keys are the row's index in the parsed file, the only handle a
 * review form uses to name a row.
 */
final readonly class GarminImportPlan
{
    /**
     * @param  array<int, GarminCsvRow>  $newRows
     * @param  array<int, GarminImportConflict>  $conflicts
     * @param  array<int, GarminCsvRow>  $alreadyImported
     * @param  int  $athleteId  The athlete planned against
     * @param  array<int, Activity>  $alreadyImportedActivities  Row index => the stored run that row is already imported as
     */
    public function __construct(
        public array $newRows,
        public array $conflicts,
        public array $alreadyImported,
        public int $athleteId,
        public array $alreadyImportedActivities = [],
    ) {}

    /**
     * A hash of what the owner was shown: whose activities were planned
     * against, which row clashes with which stored activity, and which rows
     * count as already imported. Recomputed at confirm; a different value
     * means the stored activities — or the athlete they belong to — changed
     * under the review, and the answers may now apply to other runs.
     */
    public function fingerprint(): string
    {
        $pairs = array_map(
            static fn (GarminImportConflict $conflict): int => $conflict->stored->id,
            $this->conflicts,
        );
        ksort($pairs);

        $alreadyImported = array_keys($this->alreadyImported);
        sort($alreadyImported);

        /*
         * The target is hashed too, so a plan with no conflicts does not hash
         * the same for two athletes and let the confirm write to one the review
         * never named.
         */
        return hash('sha256', (string) json_encode([
            'athlete' => $this->athleteId,
            'conflicts' => $pairs,
            'already_imported' => $alreadyImported,
        ]));
    }
}
