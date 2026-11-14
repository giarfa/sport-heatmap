<?php

namespace App\Support\Garmin;

/**
 * Pure parsers for the cell shapes a Garmin Connect CSV uses, one per value shape, shared
 * by the row parser and by the extended-metrics DTO so there is a single reading of each.
 *
 * Every parser answers null for what it cannot read: "--" (Garmin's placeholder for "not
 * recorded"), an empty cell, text that is not a number, or a value outside the bounds the
 * caller gives. The file is untrusted input, so a reading no run can have counts as "not
 * recorded", never as 0 and never as a rejection of the row.
 */
final class GarminCsvValues
{
    /**
     * The longest duration or pace Garmin can write, in seconds: 10,000 hours.
     */
    public const MAX_DURATION_SECONDS = 36_000_000;

    /**
     * No pace slower than ten hours a kilometre is a real run.
     */
    private const MAX_PACE_SECONDS_PER_KM = 36_000;

    /**
     * Left-side letters of the ground-contact balance: S for "sinistra", L for "left".
     */
    private const LEFT_LETTERS = ['S', 'L'];

    /**
     * Right-side letters: D for "destra", R for "right".
     */
    private const RIGHT_LETTERS = ['D', 'R'];

    /**
     * A whole number, with "," thousands separators handled ("1,028" → 1028) and any
     * decimal part rounded. Pass a negative `$min` for a signed value (an elevation below
     * sea level is real).
     */
    public static function wholeNumber(string $raw, int $min, int $max, bool $zeroIsEmpty): ?int
    {
        $value = self::decimalNumber($raw, $min, $max, $zeroIsEmpty, 0);

        return $value === null ? null : (int) $value;
    }

    /**
     * A number rounded to one decimal, the precision the decimal(…, 1) columns hold. It is
     * rounded here, at the boundary, so a second import compares equal to the stored value.
     */
    public static function oneDecimal(string $raw, float $min, float $max, bool $zeroIsEmpty): ?float
    {
        return self::decimalNumber($raw, $min, $max, $zeroIsEmpty, 1);
    }

    /**
     * A number with "." as the decimal mark and "," as the thousands separator, rounded to
     * `$decimals` places and kept only inside `[$min, $max]`.
     */
    public static function decimalNumber(string $raw, float $min, float $max, bool $zeroIsEmpty, int $decimals): ?float
    {
        $cleaned = str_replace(',', '', trim($raw));

        /*
         * At most nine integer digits: an unbounded value overflows the cast into a
         * negative number that would corrupt a total. The bounds below do the real work.
         */
        if (! preg_match('/^-?\d{1,9}(\.\d+)?$/', $cleaned)) {
            return null;
        }

        $value = round((float) $cleaned, $decimals);

        if ($value < $min || $value > $max || ($zeroIsEmpty && $value === 0.0)) {
            return null;
        }

        /* A rounded "-0.0" is a zero, and a signed zero would store as "-0". */
        return $value + 0.0;
    }

    /**
     * HH:MM:SS, MM:SS, or either with fractional seconds, to whole seconds.
     */
    public static function durationSeconds(string $raw): ?int
    {
        if (! preg_match('/^(?:(\d{1,4}):)?(\d{1,2}):(\d{1,2}(?:\.\d+)?)$/', trim($raw), $parts)) {
            return null;
        }

        return (int) round((int) $parts[1] * 3_600 + (int) $parts[2] * 60 + (float) $parts[3]);
    }

    /**
     * A duration that must be a real, non-zero amount of time, within what Garmin can write.
     */
    public static function positiveDurationSeconds(string $raw): ?int
    {
        $seconds = self::durationSeconds($raw);

        return $seconds === null || $seconds === 0 || $seconds > self::MAX_DURATION_SECONDS ? null : $seconds;
    }

    /**
     * A pace as whole seconds per kilometre: "5:23" → 323, "1:05:10" → 3910.
     *
     * A pace that is unreadable or zero is null, never 0 — a stored 0 would read as a real
     * measurement.
     */
    public static function paceSecondsPerKm(string $raw): ?int
    {
        $seconds = self::durationSeconds($raw);

        return $seconds === null || $seconds === 0 || $seconds > self::MAX_PACE_SECONDS_PER_KM ? null : $seconds;
    }

    /**
     * The left share of the ground-contact balance: "49.8% S / 50.2% D" (Italian) and
     * "49.8% L / 50.2% R" (English) both give 49.8.
     *
     * The left side is found by its letter, not by its position, so a file that writes the
     * right side first still yields the left share. A cell with no recognisable pair of
     * letters is null, as is a share that is 0 or above 100.
     */
    public static function leftBalancePercent(string $raw): ?float
    {
        $pattern = '/^(\d{1,3}(?:\.\d+)?)\s*%\s*([A-Za-z])\s*\/\s*(\d{1,3}(?:\.\d+)?)\s*%\s*([A-Za-z])$/';

        if (! preg_match($pattern, trim($raw), $parts)) {
            return null;
        }

        [, $firstShare, $firstLetter, $secondShare, $secondLetter] = $parts;

        $first = strtoupper($firstLetter);
        $second = strtoupper($secondLetter);

        $share = match (true) {
            in_array($first, self::LEFT_LETTERS, true) && in_array($second, self::RIGHT_LETTERS, true) => $firstShare,
            in_array($second, self::LEFT_LETTERS, true) && in_array($first, self::RIGHT_LETTERS, true) => $secondShare,
            default => null,
        };

        return $share === null ? null : self::oneDecimal($share, 0.0, 100.0, true);
    }
}
