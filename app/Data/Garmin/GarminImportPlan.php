<?php

namespace App\Data\Garmin;

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
     */
    public function __construct(
        public array $newRows,
        public array $conflicts,
        public array $alreadyImported,
    ) {}

    /**
     * A hash of what the owner was shown: which row clashes with which stored
     * activity, and which rows count as already imported. Recomputed at
     * confirm; a different value means the stored activities changed under the
     * review, and the owner's answers may now apply to other runs.
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

        return hash('sha256', (string) json_encode([
            'conflicts' => $pairs,
            'already_imported' => $alreadyImported,
        ]));
    }
}
