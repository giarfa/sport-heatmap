<?php

namespace App\Support\Garmin;

use App\Data\Garmin\GarminCsvRow;
use App\Data\Garmin\ParsedGarminCsv;
use App\Exceptions\Garmin\GarminCsvRejectedException;
use App\Support\Heatmap\HeatmapWindow;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use SplFileObject;
use Throwable;

/**
 * Reads a Garmin Connect "Activities" export into running rows inside the
 * heatmap window, counting and explaining every row it does not keep.
 *
 * Writes nothing: the result is planned and reviewed before any row is stored.
 * A file that cannot be read as an export at all is rejected whole; a single
 * bad row is listed and the rest still parse.
 */
final readonly class ParseGarminCsv
{
    private const NAME_MAX_LENGTH = 255;

    /*
     * Bounds on what one upload can put in the session and in front of the
     * planner. 5 MB of minimal rows is ~100k rows; a real 16-week export holds
     * a few hundred runs at most, and a real export with hundreds of unreadable
     * rows is not one this importer can help with.
     */
    private const MAX_RUNS = 1_000;

    /*
     * Plausibility bounds on the run metrics: the file is attacker-supplied for a
     * signed-out visitor and the values are shown on the public map, so a reading no
     * run can have counts as "not recorded".
     */
    private const MAX_PACE_SECONDS_PER_KM = 36_000;

    private const MAX_ASCENT_METERS = 20_000;

    private const MAX_HEART_RATE = 250;

    private const MAX_INVALID_ROWS = 100;

    private const MAX_IGNORED_TYPES = 50;

    private const TYPE_LABEL_MAX_LENGTH = 60;

    /**
     * @throws GarminCsvRejectedException
     */
    public function __invoke(string $path, HeatmapWindow $window, CarbonInterface $today): ParsedGarminCsv
    {
        $this->assertLooksLikeText($path);

        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl(',', '"', '');

        $columns = null;
        $rows = [];
        $ignoredByType = [];
        $outsideWindow = 0;
        $invalid = [];
        $dataRows = 0;
        $todayDate = $today->format('Y-m-d');

        foreach ($file as $index => $cells) {
            if (! is_array($cells) || $cells === [null]) {
                continue;
            }

            /** @var array<int, string|null> $cells */
            if ($columns === null) {
                $columns = GarminCsvColumns::detect($cells);

                continue;
            }

            $dataRows++;
            $line = (int) $index + 1;

            $type = trim((string) ($cells[$columns['type']] ?? ''));
            $sportType = GarminRunningTypes::sportTypeFor($type);

            if ($sportType === null) {
                $label = $type === '' ? '(no type)' : mb_substr($type, 0, self::TYPE_LABEL_MAX_LENGTH);

                if (! isset($ignoredByType[$label]) && count($ignoredByType) >= self::MAX_IGNORED_TYPES) {
                    $label = '(other types)';
                }

                $ignoredByType[$label] = ($ignoredByType[$label] ?? 0) + 1;

                continue;
            }

            $startDateLocal = $this->startDateLocal((string) ($cells[$columns['date']] ?? ''));

            if ($startDateLocal === null) {
                $invalid[] = ['line' => $line, 'reason' => 'unreadable date'];
                $this->assertWithinInvalidLimit($invalid);

                continue;
            }

            if ($startDateLocal->format('Y-m-d') > $todayDate || ! $window->contains($startDateLocal)) {
                $outsideWindow++;

                continue;
            }

            $rawDistance = trim((string) ($cells[$columns['distance']] ?? ''));
            $distanceMeters = $this->distanceMeters($rawDistance);

            if ($distanceMeters === null) {
                $invalid[] = ['line' => $line, 'reason' => in_array($rawDistance, ['', '--'], true) ? 'missing distance' : 'unreadable distance'];
                $this->assertWithinInvalidLimit($invalid);

                continue;
            }

            $movingTimeSeconds = $this->durationSeconds((string) ($cells[$columns['moving_time']] ?? ''))
                ?? (isset($columns['time']) ? $this->durationSeconds((string) ($cells[$columns['time']] ?? '')) : null);

            if ($movingTimeSeconds === null) {
                $invalid[] = ['line' => $line, 'reason' => 'missing moving time'];
                $this->assertWithinInvalidLimit($invalid);

                continue;
            }

            $row = new GarminCsvRow(
                sportType: $sportType,
                name: $this->name((string) ($cells[$columns['title']] ?? '')),
                distanceMeters: $distanceMeters,
                movingTimeSeconds: $movingTimeSeconds,
                startDateLocal: $startDateLocal,
                activityDate: $startDateLocal->startOfDay(),
                averagePaceSecondsPerKm: $this->paceSecondsPerKm($this->cell($cells, $columns, 'avg_pace')),
                totalAscentMeters: $this->ascentMeters($this->cell($cells, $columns, 'total_ascent')),
                averageHeartRate: $this->heartRate($this->cell($cells, $columns, 'avg_hr')),
            );

            /*
             * An export can list the same activity twice (a re-sync on the
             * watch, a manual duplicate). Keeping both would plan two inserts of
             * one run and double that day on the map.
             */
            $rows[$row->identityKey()] ??= $row;

            if (count($rows) > self::MAX_RUNS) {
                throw GarminCsvRejectedException::tooManyRuns(self::MAX_RUNS);
            }
        }

        if ($columns === null || $dataRows === 0) {
            throw GarminCsvRejectedException::empty();
        }

        return new ParsedGarminCsv(array_values($rows), $ignoredByType, $outsideWindow, $invalid);
    }

    /**
     * @param  array<int, array{line: int, reason: string}>  $invalid
     *
     * @throws GarminCsvRejectedException
     */
    private function assertWithinInvalidLimit(array $invalid): void
    {
        if (count($invalid) > self::MAX_INVALID_ROWS) {
            throw GarminCsvRejectedException::tooManyInvalidRows(self::MAX_INVALID_ROWS);
        }
    }

    /**
     * A CSV is text. Binary content (a spreadsheet, an image renamed .csv)
     * carries NUL bytes or invalid UTF-8, and must be rejected as "not a CSV"
     * rather than surface as a confusing "unknown headers".
     *
     * @throws GarminCsvRejectedException
     */
    private function assertLooksLikeText(string $path): void
    {
        $contents = @file_get_contents($path);

        if ($contents === false || str_contains($contents, "\0") || ! mb_check_encoding($contents, 'UTF-8')) {
            throw GarminCsvRejectedException::notACsv();
        }
    }

    /**
     * Built with createFromFormat(..., 'UTC') and no timezone conversion, the
     * same contract as StravaActivity's start_date_local: Garmin exports the
     * runner's local wall-clock time, and letting the app timezone touch it is
     * how a 22:30 run lands on the wrong day. The round-trip check rejects
     * dates PHP would silently overflow (2026-02-30 → 2 March).
     */
    private function startDateLocal(string $raw): ?CarbonImmutable
    {
        $raw = trim($raw);

        /*
         * The "!" resets every field the format does not name; without it a
         * minutes-only value would take its seconds from the current clock.
         */
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            try {
                $moment = CarbonImmutable::createFromFormat("!{$format}", $raw, 'UTC');
            } catch (Throwable) {
                continue;
            }

            if ($moment instanceof CarbonImmutable && $moment->format($format) === $raw) {
                return $moment;
            }
        }

        return null;
    }

    /**
     * Kilometres with "." as the decimal mark and "," as the thousands
     * separator — Garmin writes both that way in Italian and English exports
     * alike (the sample's "8.29" km beside "8,240" steps). "--" is Garmin's
     * placeholder for "not recorded".
     */
    private function distanceMeters(string $raw): ?int
    {
        $kilometres = str_replace(',', '', trim($raw));

        /*
         * At most six integer digits (999,999 km): an unbounded value overflows
         * the int cast into a negative distance that would corrupt the totals.
         */
        if (! preg_match('/^\d{1,6}(\.\d+)?$/', $kilometres)) {
            return null;
        }

        return (int) round((float) $kilometres * 1_000);
    }

    /**
     * HH:MM:SS, MM:SS, or either with fractional seconds, to whole seconds.
     */
    private function durationSeconds(string $raw): ?int
    {
        if (! preg_match('/^(?:(\d{1,4}):)?(\d{1,2}):(\d{1,2}(?:\.\d+)?)$/', trim($raw), $parts)) {
            return null;
        }

        return (int) round((int) $parts[1] * 3_600 + (int) $parts[2] * 60 + (float) $parts[3]);
    }

    /**
     * The raw text of an optional column, or '' when this file has no such column.
     *
     * @param  array<int, string|null>  $cells
     * @param  array<string, int>  $columns
     */
    private function cell(array $cells, array $columns, string $key): string
    {
        return isset($columns[$key]) ? trim((string) ($cells[$columns[$key]] ?? '')) : '';
    }

    /**
     * Average pace as whole seconds per kilometre: "5:23" → 323, "1:05:10" → 3910.
     *
     * Garmin writes "--" when it did not record one. A metric that is unreadable or
     * zero is null, never 0 — a stored 0 would read on the dashboard as a real
     * measurement — and it never makes the row invalid: the run itself is fine.
     */
    private function paceSecondsPerKm(string $raw): ?int
    {
        $seconds = $this->durationSeconds($raw);

        return $seconds === null || $seconds === 0 || $seconds > self::MAX_PACE_SECONDS_PER_KM ? null : $seconds;
    }

    /**
     * Total ascent as whole metres, "," thousands separators handled ("1,234" → 1234),
     * with an optional decimal part rounded. A flat run's 0 is a real reading and stays 0.
     */
    private function ascentMeters(string $raw): ?int
    {
        $metres = str_replace(',', '', $raw);

        if (! preg_match('/^\d{1,6}(\.\d+)?$/', $metres)) {
            return null;
        }

        $ascent = (int) round((float) $metres);

        return $ascent > self::MAX_ASCENT_METERS ? null : $ascent;
    }

    /**
     * Average heart rate as whole bpm. Up to three digits keeps a malformed cell from
     * becoming an absurd reading; 0 means the strap was off, so it is not recorded.
     */
    private function heartRate(string $raw): ?int
    {
        if (! preg_match('/^\d{1,3}(\.\d+)?$/', $raw)) {
            return null;
        }

        $bpm = (int) round((float) $raw);

        return $bpm === 0 || $bpm > self::MAX_HEART_RATE ? null : $bpm;
    }

    private function name(string $raw): string
    {
        $name = trim($raw);

        return $name === '' ? 'Run' : mb_substr($name, 0, self::NAME_MAX_LENGTH);
    }
}
