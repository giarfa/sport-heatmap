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
     * Canonical key => header text, per language. The first entry of each set
     * is the export's first column and decides the language.
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
        ],
        'en' => [
            'type' => 'Activity Type',
            'date' => 'Date',
            'title' => 'Title',
            'distance' => 'Distance',
            'moving_time' => 'Moving Time',
            'time' => 'Time',
        ],
    ];

    /**
     * Read when moving time is "--"; a file without it is still accepted.
     *
     * @var array<int, string>
     */
    private const OPTIONAL_KEYS = ['time'];

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

        $headerSet = self::headerSetFor($header[0] ?? '');

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
     * @return array<string, string>
     *
     * @throws GarminCsvRejectedException
     */
    private static function headerSetFor(string $firstCell): array
    {
        foreach (self::HEADER_SETS as $headerSet) {
            if ($headerSet['type'] === $firstCell) {
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
