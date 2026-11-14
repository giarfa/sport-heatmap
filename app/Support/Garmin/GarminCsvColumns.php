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
     * Canonical key => accepted header texts, per language. The activity-type header
     * decides the language. A key lists several texts when Garmin spells its header more
     * than one way; the first one is the canonical spelling.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const HEADER_SETS = [
        'it' => [
            'type' => ['Tipo di attività'],
            'date' => ['Data'],
            'title' => ['Titolo'],
            'distance' => ['Distanza'],
            'moving_time' => ['Tempo in movimento'],
            'time' => ['Tempo'],
            'avg_pace' => ['Passo medio'],
            'total_ascent' => ['Ascesa totale'],
            'avg_hr' => ['FC Media'],
            'calories' => ['Calorie'],
            'max_hr' => ['FC max'],
            'aerobic_te' => ['TE aerobico'],
            'avg_cadence' => ['Cadenza di corsa media'],
            'max_cadence' => ['Cadenza di corsa max'],
            'best_pace' => ['Passo migliore'],
            'total_descent' => ['Discesa totale'],
            'stride_length' => ['Lunghezza media passo'],
            'vertical_ratio' => ['Rapporto verticale medio'],
            'vertical_oscillation' => ['Oscillazione verticale media'],
            'ground_contact_time' => ['Tempo medio di contatto con il suolo'],
            'gct_balance' => ['Bilanciamento TCS medio'],
            'steps' => ['Passi'],
            'laps' => ['Numero di Lap'],
            'elapsed_time' => ['Tempo trascorso'],
            'min_elevation' => ['Quota minima'],
            'max_elevation' => ['Quota massima'],
            'training_stress_score' => ['Training Stress Score®'],
        ],
        'en' => [
            'type' => ['Activity Type'],
            'date' => ['Date'],
            'title' => ['Title'],
            'distance' => ['Distance'],
            'moving_time' => ['Moving Time'],
            'time' => ['Time'],
            'avg_pace' => ['Avg Pace'],
            'total_ascent' => ['Total Ascent'],
            'avg_hr' => ['Avg HR'],
            'calories' => ['Calories'],
            'max_hr' => ['Max HR'],
            'aerobic_te' => ['Aerobic TE'],
            'avg_cadence' => ['Avg Run Cadence'],
            'max_cadence' => ['Max Run Cadence'],
            'best_pace' => ['Best Pace'],
            'total_descent' => ['Total Descent'],
            'stride_length' => ['Avg Stride Length'],
            'vertical_ratio' => ['Avg Vertical Ratio'],
            'vertical_oscillation' => ['Avg Vertical Oscillation'],
            'ground_contact_time' => ['Avg Ground Contact Time'],
            'gct_balance' => ['Avg GCT Balance'],
            'steps' => ['Steps'],
            'laps' => ['Number of Laps'],
            'elapsed_time' => ['Elapsed Time'],
            'min_elevation' => ['Min Elev', 'Min Elevation'],
            'max_elevation' => ['Max Elev', 'Max Elevation'],
            'training_stress_score' => ['Training Stress Score®'],
        ],
    ];

    /*
     * The headers of the three run metrics (decision 98fe4bd16f3263d6) and of the
     * extended metrics (US-023). The Italian ones are read off real exports. The English
     * ones are Garmin's standard names (decision "us-023 english garmin extended
     * headers") and are unconfirmed by a real English export: where Garmin's spelling
     * varies the alternatives are listed above, and if a real export differs the fix is
     * one line here — until then an English file simply imports without that metric.
     */

    /**
     * The keys of the extended run metrics, in the order the plan lists them. They are
     * optional like the three run metrics, and the DTO that reads them takes exactly
     * these keys.
     *
     * @var list<string>
     */
    public const EXTENDED_KEYS = [
        'calories',
        'max_hr',
        'aerobic_te',
        'avg_cadence',
        'max_cadence',
        'best_pace',
        'total_descent',
        'stride_length',
        'vertical_ratio',
        'vertical_oscillation',
        'ground_contact_time',
        'gct_balance',
        'steps',
        'laps',
        'elapsed_time',
        'min_elevation',
        'max_elevation',
        'training_stress_score',
    ];

    /**
     * Columns a file may lack and still be accepted: "time" is read when moving time
     * is "--", and the run metrics and extended metrics are read when present. A missing
     * optional column is never a rejection and never counts toward language detection.
     *
     * @var list<string>
     */
    private const OPTIONAL_KEYS = ['time', 'avg_pace', 'total_ascent', 'avg_hr', ...self::EXTENDED_KEYS];

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

        foreach ($headerSet as $key => $headerTexts) {
            $index = self::indexOfAny($headerTexts, $header);

            if ($index === null) {
                if (in_array($key, self::OPTIONAL_KEYS, true)) {
                    continue;
                }

                throw GarminCsvRejectedException::missingColumn($headerTexts[0]);
            }

            $columns[$key] = $index;
        }

        return $columns;
    }

    /**
     * The language whose headers the row carries, wherever they sit.
     *
     * Its activity-type header plus all but one of the other required headers
     * must be present: other exports reuse single names ("Activity Type",
     * "Distance" and "Moving Time"), and a Garmin export
     * missing one column should hear which one, not "unrecognised".
     *
     * @param  array<int, string>  $header
     * @return array<string, list<string>>
     *
     * @throws GarminCsvRejectedException
     */
    private static function headerSetFor(array $header): array
    {
        foreach (self::HEADER_SETS as $headerSet) {
            $required = array_diff_key($headerSet, array_flip(self::OPTIONAL_KEYS));
            $present = array_filter(
                $required,
                static fn (array $headerTexts): bool => self::indexOfAny($headerTexts, $header) !== null,
            );

            if (isset($present['type']) && count($present) >= count($required) - 1) {
                return $headerSet;
            }
        }

        throw GarminCsvRejectedException::unrecognisedHeaders();
    }

    /**
     * The index of the first header cell that is one of the accepted texts, or null.
     *
     * @param  list<string>  $headerTexts
     * @param  array<int, string>  $header
     */
    private static function indexOfAny(array $headerTexts, array $header): ?int
    {
        foreach ($header as $index => $cell) {
            if (in_array($cell, $headerTexts, true)) {
                return $index;
            }
        }

        return null;
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
