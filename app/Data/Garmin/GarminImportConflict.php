<?php

namespace App\Data\Garmin;

use App\Models\Activity;

/**
 * A CSV row and the stored run it clashes with: same local date, starts within
 * ±2 minutes, the closest one still unclaimed.
 */
final readonly class GarminImportConflict
{
    public function __construct(
        public int $rowIndex,
        public GarminCsvRow $row,
        public Activity $stored,
    ) {}
}
