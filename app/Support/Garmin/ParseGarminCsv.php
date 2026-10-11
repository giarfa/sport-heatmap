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
                $label = $type === '' ? '(no type)' : $type;
                $ignoredByType[$label] = ($ignoredByType[$label] ?? 0) + 1;

                continue;
            }

            $startDateLocal = $this->startDateLocal((string) ($cells[$columns['date']] ?? ''));

            if ($startDateLocal === null) {
                $invalid[] = ['line' => $line, 'reason' => 'unreadable date'];

                continue;
            }

            if ($startDateLocal->format('Y-m-d') > $todayDate || ! $window->contains($startDateLocal)) {
                $outsideWindow++;

                continue;
            }

            $distanceMeters = $this->distanceMeters((string) ($cells[$columns['distance']] ?? ''));

            if ($distanceMeters === null) {
                $invalid[] = ['line' => $line, 'reason' => 'missing distance'];

                continue;
            }

            $movingTimeSeconds = $this->durationSeconds((string) ($cells[$columns['moving_time']] ?? ''))
                ?? (isset($columns['time']) ? $this->durationSeconds((string) ($cells[$columns['time']] ?? '')) : null);

            if ($movingTimeSeconds === null) {
                $invalid[] = ['line' => $line, 'reason' => 'missing moving time'];

                continue;
            }

            $row = new GarminCsvRow(
                sportType: $sportType,
                name: $this->name((string) ($cells[$columns['title']] ?? '')),
                distanceMeters: $distanceMeters,
                movingTimeSeconds: $movingTimeSeconds,
                startDateLocal: $startDateLocal,
                activityDate: $startDateLocal->startOfDay(),
            );

            /*
             * An export can list the same activity twice (a re-sync on the
             * watch, a manual duplicate). Keeping both would plan two inserts of
             * one run and double that day on the map.
             */
            $rows[$row->identityKey()] ??= $row;
        }

        if ($columns === null || $dataRows === 0) {
            throw GarminCsvRejectedException::empty();
        }

        return new ParsedGarminCsv(array_values($rows), $ignoredByType, $outsideWindow, $invalid);
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

        if (! preg_match('/^\d+(\.\d+)?$/', $kilometres)) {
            return null;
        }

        return (int) round((float) $kilometres * 1_000);
    }

    /**
     * HH:MM:SS, MM:SS, or either with fractional seconds, to whole seconds.
     */
    private function durationSeconds(string $raw): ?int
    {
        if (! preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{1,2}(?:\.\d+)?)$/', trim($raw), $parts)) {
            return null;
        }

        return (int) round((int) $parts[1] * 3_600 + (int) $parts[2] * 60 + (float) $parts[3]);
    }

    private function name(string $raw): string
    {
        $name = trim($raw);

        return $name === '' ? 'Run' : mb_substr($name, 0, self::NAME_MAX_LENGTH);
    }
}
