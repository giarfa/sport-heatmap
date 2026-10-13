<?php

namespace Tests\Unit\Support\Garmin;

use App\Support\Garmin\GarminImportFormat;
use PHPUnit\Framework\TestCase;

class GarminImportFormatTest extends TestCase
{
    public function test_a_pace_prints_minutes_and_two_digit_seconds_per_kilometre(): void
    {
        $this->assertSame('5:23 /km', GarminImportFormat::pace(323));
        $this->assertSame('4:05 /km', GarminImportFormat::pace(245));
        $this->assertSame('65:10 /km', GarminImportFormat::pace(3_910));
    }

    public function test_ascent_and_heart_rate_carry_their_unit(): void
    {
        $this->assertSame('45 m', GarminImportFormat::ascent(45));
        $this->assertSame('0 m', GarminImportFormat::ascent(0));
        $this->assertSame('152 bpm', GarminImportFormat::heartRate(152));
    }

    public function test_a_metric_that_was_not_recorded_is_null_never_a_zero_string(): void
    {
        $this->assertNull(GarminImportFormat::pace(null));
        $this->assertNull(GarminImportFormat::ascent(null));
        $this->assertNull(GarminImportFormat::heartRate(null));
    }
}
