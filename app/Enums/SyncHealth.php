<?php

namespace App\Enums;

/**
 * The four states FR-007 branches the dashboard's sync-status indicator on.
 *
 * `SyncStatus::from()` decides between these with a fixed precedence: a
 * failure always wins over how recent the last success was, because the map
 * is now frozen at that last good sync.
 */
enum SyncHealth
{
    case Healthy;
    case Failed;
    case Stale;
    case NeverSynced;
}
