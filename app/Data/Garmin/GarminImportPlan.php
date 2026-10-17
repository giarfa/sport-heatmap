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
     * @param  int|null  $athleteId  The athlete planned against; null when none exists yet
     * @param  array<int, Activity>  $alreadyImportedActivities  Row index => the stored run that row is already imported as
     */
    public function __construct(
        public array $newRows,
        public array $conflicts,
        public array $alreadyImported,
        public ?int $athleteId,
        public array $alreadyImportedActivities = [],
    ) {}

    /**
     * A hash of what the visitor was shown: whose activities were planned
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
         * The target is hashed too. A guest's target can move between review
         * and confirm — an allowlisted athlete connects, the visitor signs in,
         * another guest's first import creates the CSV-only athlete — and a
         * plan with no conflicts would otherwise hash the same for both
         * athletes, letting the confirm write to one the review never named.
         */
        return hash('sha256', (string) json_encode([
            'athlete' => $this->athleteId,
            'conflicts' => $pairs,
            'already_imported' => $alreadyImported,
        ]));
    }
}
