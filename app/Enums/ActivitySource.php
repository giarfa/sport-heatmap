<?php

namespace App\Enums;

/**
 * Where a stored activity's values came from.
 *
 * `Strava` rows are owned by the nightly sync. `GarminCsv` rows were written by a
 * Garmin Connect CSV import — either imported new (no Strava id) or a Strava twin
 * the owner chose to overwrite (Strava id kept) — and the sync never reverts,
 * re-adds or deletes them (FR-002, FR-018).
 */
enum ActivitySource: string
{
    case Strava = 'strava';
    case GarminCsv = 'garmin_csv';
}
