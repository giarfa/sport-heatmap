<?php

namespace App\Enums;

/**
 * The five states FR-007 branches the dashboard's sync-status indicator on.
 *
 * `SyncStatus::from()` decides between the first four with a fixed
 * precedence: a failure always wins over how recent the last success was,
 * because the map is now frozen at that last good sync. `CsvOnly` comes only
 * from `SyncStatus::forCsvOnly()`: an athlete with no Strava connection, whose
 * map changes only when a Garmin CSV is imported, so it never warns.
 */
enum SyncHealth
{
    case Healthy;
    case Failed;
    case Stale;
    case NeverSynced;
    case CsvOnly;
}
