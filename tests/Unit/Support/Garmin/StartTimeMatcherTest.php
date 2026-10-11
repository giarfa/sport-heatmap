<?php

namespace Tests\Unit\Support\Garmin;

use App\Models\Activity;
use App\Support\Garmin\StartTimeMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StartTimeMatcherTest extends TestCase
{
    /**
     * @return array<string, array{int, bool}>
     */
    public static function boundaryOffsets(): array
    {
        return [
            '119 s before matches' => [-119, true],
            '120 s before matches' => [-120, true],
            '121 s before does not' => [-121, false],
            '119 s after matches' => [119, true],
            '120 s after matches' => [120, true],
            '121 s after does not' => [121, false],
        ];
    }

    #[DataProvider('boundaryOffsets')]
    public function test_the_two_minute_tolerance_is_inclusive(int $offsetSeconds, bool $matches): void
    {
        $candidate = $this->storedActivity(1, Carbon::parse('2026-10-10 09:33:27')->addSeconds($offsetSeconds));

        $match = (new StartTimeMatcher)->closest($this->csvStart('2026-10-10 09:33:27'), [$candidate]);

        $this->assertSame($matches, $match !== null);
    }

    /**
     * Just after midnight vs just before it: 60 s apart as instants, but on two
     * different local dates, so never the same run.
     */
    public function test_a_start_on_a_different_date_never_matches(): void
    {
        $candidate = $this->storedActivity(1, Carbon::parse('2026-10-09 23:59:30'));

        $this->assertNull((new StartTimeMatcher)->closest($this->csvStart('2026-10-10 00:00:30'), [$candidate]));
    }

    public function test_the_closest_of_two_candidates_wins(): void
    {
        $farther = $this->storedActivity(1, Carbon::parse('2026-10-10 09:31:40'));
        $closer = $this->storedActivity(2, Carbon::parse('2026-10-10 09:34:00'));

        $match = (new StartTimeMatcher)->closest($this->csvStart('2026-10-10 09:33:27'), [$farther, $closer]);

        $this->assertSame(2, $match?->id);
    }

    public function test_a_tie_goes_to_the_lowest_id_whatever_the_order(): void
    {
        $before = $this->storedActivity(7, Carbon::parse('2026-10-10 09:32:27'));
        $after = $this->storedActivity(3, Carbon::parse('2026-10-10 09:34:27'));

        $matcher = new StartTimeMatcher;
        $start = $this->csvStart('2026-10-10 09:33:27');

        $this->assertSame(3, $matcher->closest($start, [$before, $after])?->id);
        $this->assertSame(3, $matcher->closest($start, [$after, $before])?->id);
    }

    /**
     * Stored starts come back from Eloquent in the app timezone, CSV starts are
     * built in UTC; both are wall-clock readings and must compare as such.
     */
    public function test_wall_clock_readings_match_regardless_of_the_instance_timezone(): void
    {
        $defaultTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Rome');

        try {
            $candidate = $this->storedActivity(1, Carbon::parse('2026-10-10 09:33:27'));

            $this->assertSame('Europe/Rome', $candidate->start_date_local->getTimezone()->getName());
            $this->assertNotNull((new StartTimeMatcher)->closest($this->csvStart('2026-10-10 09:33:27'), [$candidate]));
        } finally {
            date_default_timezone_set($defaultTimezone);
        }
    }

    private function csvStart(string $wallClock): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $wallClock, 'UTC');
    }

    private function storedActivity(int $id, Carbon $start): Activity
    {
        return (new Activity)->forceFill([
            'id' => $id,
            'start_date_local' => $start,
            'activity_date' => $start->toDateString(),
        ]);
    }
}
