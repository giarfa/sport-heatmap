<?php

namespace Tests\Unit\Support\Garmin;

use App\Data\Garmin\GarminCsvRow;
use App\Data\Garmin\GarminImportPlan;
use App\Enums\SportType;
use App\Models\Activity;
use App\Models\User;
use App\Support\Garmin\PlanGarminImport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The claiming rules the review and the confirm both depend on, pinned apart from
 * the HTTP flow: identity first, closest pair first, ties to the earlier row then
 * the lower stored id, and rows compared only with the stored runs of their date.
 */
class PlanGarminImportTest extends TestCase
{
    use RefreshDatabase;

    private User $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete = User::factory()->create();
    }

    public function test_a_row_identical_to_a_stored_garmin_run_is_already_imported_and_does_not_leave_it_as_a_clash_for_a_nearby_row(): void
    {
        $stored = $this->storedRun('2026-10-10 09:33:27');

        $plan = $this->plan([
            $this->row('2026-10-10 09:32:30', distance: 5000),
            $this->row('2026-10-10 09:33:27'),
        ]);

        $this->assertSame([1], array_keys($plan->alreadyImported));
        $this->assertSame($stored->id, $plan->alreadyImportedActivities[1]->id);
        $this->assertSame([], array_keys($plan->conflicts));
        $this->assertSame([0], array_keys($plan->newRows));
    }

    public function test_a_stored_run_is_claimed_by_one_identical_row_only(): void
    {
        $this->storedRun('2026-10-10 09:33:27');

        $plan = $this->plan([$this->row('2026-10-10 09:33:27'), $this->row('2026-10-10 09:33:27')]);

        $this->assertSame([0], array_keys($plan->alreadyImported));
        $this->assertSame([1], array_keys($plan->newRows));
    }

    public function test_a_run_imported_in_an_earlier_session_is_a_clash_for_a_nearby_row_that_differs(): void
    {
        $stored = $this->storedRun('2026-10-10 09:33:27');

        $plan = $this->plan([$this->row('2026-10-10 09:34:10', distance: 8150)]);

        $this->assertSame([], $plan->alreadyImported);
        $this->assertSame([], $plan->newRows);
        $this->assertSame($stored->id, $plan->conflicts[0]->stored->id);
    }

    public function test_the_closest_row_claims_a_stored_run_before_an_earlier_but_farther_one(): void
    {
        $stored = $this->storedRun('2026-10-10 09:33:27');

        $plan = $this->plan([
            $this->row('2026-10-10 09:31:57'),
            $this->row('2026-10-10 09:33:27', distance: 5000),
        ]);

        $this->assertSame([1], array_keys($plan->conflicts));
        $this->assertSame($stored->id, $plan->conflicts[1]->stored->id);
        $this->assertSame([0], array_keys($plan->newRows));
    }

    public function test_two_rows_the_same_distance_from_a_stored_run_go_to_the_earlier_row(): void
    {
        $this->storedRun('2026-10-10 09:33:27');

        $plan = $this->plan([$this->row('2026-10-10 09:32:27'), $this->row('2026-10-10 09:34:27')]);

        $this->assertSame([0], array_keys($plan->conflicts));
        $this->assertSame([1], array_keys($plan->newRows));
    }

    public function test_two_stored_runs_the_same_distance_from_a_row_go_to_the_lower_id(): void
    {
        $lower = $this->storedRun('2026-10-10 09:34:27');
        $this->storedRun('2026-10-10 09:32:27');

        $plan = $this->plan([$this->row('2026-10-10 09:33:27')]);

        $this->assertSame($lower->id, $plan->conflicts[0]->stored->id);
    }

    public function test_a_row_is_never_compared_with_a_stored_run_of_another_date_in_the_same_upload(): void
    {
        $this->storedRun('2026-10-10 00:00:30');

        $plan = $this->plan([$this->row('2026-10-09 23:59:30'), $this->row('2026-10-10 12:00:00')]);

        $this->assertSame([], $plan->conflicts);
        $this->assertSame([0, 1], array_keys($plan->newRows));
    }

    /**
     * @param  array<int, GarminCsvRow>  $rows
     */
    private function plan(array $rows): GarminImportPlan
    {
        return $this->app->make(PlanGarminImport::class)($this->athlete, $rows);
    }

    private function row(string $start, int $distance = 8290): GarminCsvRow
    {
        $startDateLocal = CarbonImmutable::parse($start, 'UTC');

        return new GarminCsvRow(SportType::Run, 'Paese Corsa', $distance, 3263, $startDateLocal, $startDateLocal->startOfDay());
    }

    private function storedRun(string $start): Activity
    {
        return Activity::factory()->for($this->athlete)->create([
            'start_date_local' => $start,
            'activity_date' => substr($start, 0, 10),
            'distance_meters' => 8290,
            'moving_time_seconds' => 3263,
        ]);
    }
}
