<?php

namespace App\Exceptions\Garmin;

use RuntimeException;

/**
 * The uploaded file cannot be imported at all, so nothing from it is written.
 *
 * Every message is written for the owner and shown under the upload field. It
 * carries no file content beyond a column name, so an upload can never echo
 * its own bytes back into the page.
 */
class GarminCsvRejectedException extends RuntimeException
{
    public static function notACsv(): self
    {
        return new self('This is not a CSV file.');
    }

    public static function unrecognisedHeaders(): self
    {
        return new self('This is not a Garmin Connect activities export. Use the Italian or English Activities CSV.');
    }

    public static function missingColumn(string $column): self
    {
        return new self("The file is missing the “{$column}” column. Export the Activities list again from Garmin Connect.");
    }

    public static function empty(): self
    {
        return new self('The file has no activities.');
    }

    public static function tooManyRuns(int $limit): self
    {
        return new self("The file has more than {$limit} runs in the last 16 weeks. Export a shorter period from Garmin Connect.");
    }

    public static function tooManyInvalidRows(int $limit): self
    {
        return new self("More than {$limit} rows could not be read. Export the Activities list again from Garmin Connect.");
    }
}
