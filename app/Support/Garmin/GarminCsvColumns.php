<?php

namespace App\Support\Garmin;

use App\Exceptions\Garmin\GarminCsvRejectedException;

/**
 * The header sets of a Garmin Connect "Activities" export, in the two account
 * languages this product accepts.
 *
 * Garmin translates the header row into the account language and keeps the
 * column order free to change between exports, so columns are located by
 * name, never by position.
 */
final class GarminCsvColumns
{
    /**
     * Canonical key => header text, per language. The activity-type header
     * decides the language.
     *
     * @var array<string, array<string, string>>
     */
    private const HEADER_SETS = [
        'it' => [
            'type' => 'Tipo di attività',
            'date' => 'Data',
            'title' => 'Titolo',
            'distance' => 'Distanza',
            'moving_time' => 'Tempo in movimento',
            'time' => 'Tempo',
            'avg_pace' => 'Passo medio',
            'total_ascent' => 'Ascesa totale',
            'avg_hr' => 'FC Media',
        ],
        'en' => [
            'type' => 'Activity Type',
            'date' => 'Date',
            'title' => 'Title',
            'distance' => 'Distance',
            'moving_time' => 'Moving Time',
            'time' => 'Time',
            'avg_pace' => 'Avg Pace',
            'total_ascent' => 'Total Ascent',
            'avg_hr' => 'Avg HR',
        ],
    ];

    /*
     * The three run-metric headers (decision 98fe4bd16f3263d6). The Italian ones are
     * read off a real export; the English ones follow the existing English fixture
     * and are unconfirmed by a real English export — if a real one differs, the fix
     * is one line here, and until then an English file simply imports without metrics.
     */

    /**
     * Columns a file may lack and still be accepted: "time" is read when moving time
     * is "--", and the three run metrics are read when present. A missing optional
     * column is never a rejection and never counts toward language detection.
     *
     * @var array<int, string>
     */
    private const OPTIONAL_KEYS = ['time', 'avg_pace', 'total_ascent', 'avg_hr'];

    /**
     * Map each canonical key to its column index in this file's header row.
     *
     * @param  array<int, string|null>  $header
     * @return array<string, int>
     *
     * @throws GarminCsvRejectedException
     */
    public static function detect(array $header): array
    {
        $header = array_map(static fn (?string $cell): string => trim((string) $cell), $header);

        if (isset($header[0])) {
            $header[0] = self::withoutByteOrderMark($header[0]);
        }

        $headerSet = self::headerSetFor($header);

        $columns = [];

        foreach ($headerSet as $key => $headerText) {
            $index = array_search($headerText, $header, true);

            if ($index === false) {
                if (in_array($key, self::OPTIONAL_KEYS, true)) {
                    continue;
                }

                throw GarminCsvRejectedException::missingColumn($headerText);
            }

            $columns[$key] = (int) $index;
        }

        return $columns;
    }

    /**
     * The language whose headers the row carries, wherever they sit.
     *
     * Its activity-type header plus all but one of the other required headers
     * must be present: other exports reuse single names (Strava's has
     * "Activity Type", "Distance" and "Moving Time"), and a Garmin export
     * missing one column should hear which one, not "unrecognised".
     *
     * @param  array<int, string>  $header
     * @return array<string, string>
     *
     * @throws GarminCsvRejectedException
     */
    private static function headerSetFor(array $header): array
    {
        foreach (self::HEADER_SETS as $headerSet) {
            $required = array_diff_key($headerSet, array_flip(self::OPTIONAL_KEYS));
            $present = array_intersect($required, $header);

            if (in_array($headerSet['type'], $header, true) && count($present) >= count($required) - 1) {
                return $headerSet;
            }
        }

        throw GarminCsvRejectedException::unrecognisedHeaders();
    }

    /**
     * Excel and some Garmin exports prefix the file with a UTF-8 BOM, which
     * would otherwise make the first header never equal "Activity Type".
     */
    private static function withoutByteOrderMark(string $cell): string
    {
        return str_starts_with($cell, "\u{FEFF}") ? substr($cell, 3) : $cell;
    }
}
