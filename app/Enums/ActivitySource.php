<?php

namespace App\Enums;

/**
 * Where a stored run came from.
 *
 * A run that exists in both places is one run: when the sync links a CSV run it
 * becomes `GarminApi`, because from then on the API is authoritative for its values.
 */
enum ActivitySource: string
{
    case GarminCsv = 'garmin_csv';
    case GarminApi = 'garmin_api';
}
