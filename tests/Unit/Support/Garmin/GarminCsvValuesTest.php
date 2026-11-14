<?php

namespace Tests\Unit\Support\Garmin;

use App\Support\Garmin\GarminCsvValues;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GarminCsvValuesTest extends TestCase
{
    /**
     * @return array<string, array{string, int|null}>
     */
    public static function wholeNumbers(): array
    {
        return [
            'a plain number' => ['302', 302],
            'thousands separator' => ['1,028', 1_028],
            'a decimal part is rounded' => ['148.6', 149],
            'the placeholder is empty' => ['--', null],
            'an empty cell is empty' => ['', null],
            'text is empty' => ['abc', null],
            'zero is empty when asked' => ['0', null],
            'above the maximum is empty' => ['20001', null],
            'a negative is below the minimum' => ['-3', null],
            'surrounding space is ignored' => [' 171 ', 171],
        ];
    }

    #[DataProvider('wholeNumbers')]
    public function test_a_whole_number_zero_is_empty_and_bounded(string $raw, ?int $expected): void
    {
        $this->assertSame($expected, GarminCsvValues::wholeNumber($raw, 0, 20_000, true));
    }

    public function test_a_whole_number_keeps_zero_when_zero_is_a_reading(): void
    {
        $this->assertSame(0, GarminCsvValues::wholeNumber('0', 0, 20_000, false));
        $this->assertSame(0, GarminCsvValues::wholeNumber('0.4', 0, 20_000, false));
    }

    public function test_a_signed_whole_number_keeps_negatives_and_zero_inside_its_bounds(): void
    {
        $this->assertSame(-12, GarminCsvValues::wholeNumber('-12', -500, 9_000, false));
        $this->assertSame(0, GarminCsvValues::wholeNumber('0', -500, 9_000, false));
        $this->assertSame(1_065, GarminCsvValues::wholeNumber('1,065', -500, 9_000, false));
        $this->assertNull(GarminCsvValues::wholeNumber('-501', -500, 9_000, false));
        $this->assertNull(GarminCsvValues::wholeNumber('9001', -500, 9_000, false));
        $this->assertNull(GarminCsvValues::wholeNumber('99999999999', -500, 9_000, false));
    }

    public function test_a_value_that_rounds_to_negative_zero_is_a_plain_zero(): void
    {
        $value = GarminCsvValues::oneDecimal('-0.04', -1.0, 5.0, false);

        $this->assertSame(0.0, $value);
        $this->assertSame('0', (string) $value);
    }

    public function test_one_decimal_rounds_to_one_place_and_applies_its_bounds(): void
    {
        $this->assertSame(9.6, GarminCsvValues::oneDecimal('9.6', 0.0, 50.0, true));
        $this->assertSame(3.8, GarminCsvValues::oneDecimal('3.84', 0.0, 5.0, false));
        $this->assertSame(0.0, GarminCsvValues::oneDecimal('0.0', 0.0, 5.0, false));
        $this->assertNull(GarminCsvValues::oneDecimal('0.0', 0.0, 10_000.0, true));
        $this->assertNull(GarminCsvValues::oneDecimal('5.7', 0.0, 5.0, false));
        $this->assertNull(GarminCsvValues::oneDecimal('--', 0.0, 5.0, false));
    }

    /**
     * @return array<string, array{string, int|null}>
     */
    public static function durations(): array
    {
        return [
            'minutes and seconds' => ['5:23', 323],
            'hours' => ['1:05:10', 3_910],
            'padded' => ['00:54:23', 3_263],
            'fractional seconds' => ['00:40:12.6', 2_413],
            'placeholder' => ['--', null],
            'text' => ['4:xx', null],
            'five-digit hours' => ['99999:00:00', null],
        ];
    }

    #[DataProvider('durations')]
    public function test_a_duration_reads_both_grammars(string $raw, ?int $expected): void
    {
        $this->assertSame($expected, GarminCsvValues::durationSeconds($raw));
    }

    public function test_a_pace_is_never_zero_and_never_absurd(): void
    {
        $this->assertSame(323, GarminCsvValues::paceSecondsPerKm('5:23'));
        $this->assertNull(GarminCsvValues::paceSecondsPerKm('0:00'));
        $this->assertNull(GarminCsvValues::paceSecondsPerKm('11:00:00'));
        $this->assertNull(GarminCsvValues::paceSecondsPerKm('--'));
    }

    public function test_an_elapsed_duration_is_never_zero(): void
    {
        $this->assertSame(5_620, GarminCsvValues::positiveDurationSeconds('01:33:40'));
        $this->assertNull(GarminCsvValues::positiveDurationSeconds('00:00:00'));
        $this->assertNull(GarminCsvValues::positiveDurationSeconds('--'));
    }

    /**
     * @return array<string, array{string, float|null}>
     */
    public static function balances(): array
    {
        return [
            'Italian, left first' => ['49.8% S / 50.2% D', 49.8],
            'English, left first' => ['49.8% L / 50.2% R', 49.8],
            'the left side by its letter, right written first' => ['50.2% D / 49.8% S', 49.8],
            'lower-case letters' => ['47.5% l / 52.5% r', 47.5],
            'no spaces around the percent sign' => ['49.8%S/50.2%D', 49.8],
            'two left letters' => ['50% S / 50% L', null],
            'unknown letters' => ['50% X / 50% Y', null],
            'a bare number' => ['49.8', null],
            'a zero share' => ['0.0% S / 100.0% D', null],
            'above a hundred' => ['120% S / 0% D', null],
            'the placeholder' => ['--', null],
        ];
    }

    #[DataProvider('balances')]
    public function test_the_left_balance_share_is_found_by_its_letter(string $raw, ?float $expected): void
    {
        $this->assertSame($expected, GarminCsvValues::leftBalancePercent($raw));
    }
}
