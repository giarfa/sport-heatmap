<?php

namespace Tests\Feature\Garmin;

use App\Actions\Garmin\ApplyGarminImport;
use App\Data\Garmin\GarminCsvRow;
use App\Data\Garmin\GarminImportPlan;
use App\Data\Garmin\GarminImportSummary;
use App\Data\Garmin\ParsedGarminCsv;
use App\Enums\ActivitySource;
use App\Enums\SportType;
use App\Models\Activity;
use App\Models\User;
use App\Support\Garmin\PlanGarminImport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * An import with no target athlete: the first confirmed import that has runs
 * creates the single CSV-only athlete, in the same transaction as its runs.
 */
class CsvOnlyAthleteImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo('2026-10-11 12:00:00');
    }

    public function test_an_import_with_no_target_creates_one_csv_only_athlete_owning_the_runs(): void
    {
        $plan = $this->plan(null, [$this->row('2026-10-10 09:33:27'), $this->row('2026-10-08 18:05:00')]);

        $summary = $this->apply(null, $plan);

        $athlete = User::sole();
        $this->assertSame('Garmin CSV athlete', $athlete->name);
        $this->assertSame('csv-athlete@garmin.invalid', $athlete->email);
        $this->assertNull($athlete->strava_athlete_id);
        $this->assertNull($athlete->strava_access_token);
        $this->assertNull($athlete->strava_refresh_token);
        $this->assertNull($athlete->password);
        $this->assertTrue($athlete->isCsvOnly());
        $this->assertSame(2, $athlete->activities()->where('source', ActivitySource::GarminCsv)->count());
        $this->assertSame(2, $summary->imported);
    }

    public function test_a_second_import_with_no_target_reuses_the_csv_only_athlete(): void
    {
        $this->apply(null, $this->plan(null, [$this->row('2026-10-10 09:33:27')]));

        $this->apply(null, $this->plan(null, [$this->row('2026-10-08 18:05:00')]));

        $this->assertSame(1, User::count());
        $this->assertSame(2, User::sole()->activities()->count());
    }

    public function test_an_import_with_no_target_and_nothing_to_add_creates_no_athlete(): void
    {
        $summary = $this->apply(null, $this->plan(null, []));

        $this->assertSame(0, User::count());
        $this->assertSame(0, Activity::count());
        $this->assertSame(0, $summary->imported);
    }

    public function test_a_failed_insert_rolls_the_csv_only_athlete_back_with_its_runs(): void
    {
        Activity::creating(static function (): never {
            throw new RuntimeException('Insert failed.');
        });

        try {
            $this->apply(null, $this->plan(null, [$this->row('2026-10-10 09:33:27')]));
            $this->fail('The insert should have failed.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, User::count());
        $this->assertSame(0, Activity::count());
    }

    public function test_the_fingerprint_changes_when_only_the_target_changes(): void
    {
        $first = User::factory()->csvOnly()->create();
        $second = User::factory()->stravaConnected()->create();
        $rows = [$this->row('2026-10-10 09:33:27')];

        $fingerprints = [
            $this->plan(null, $rows)->fingerprint(),
            $this->plan($first, $rows)->fingerprint(),
            $this->plan($second, $rows)->fingerprint(),
        ];

        $this->assertCount(3, array_unique($fingerprints));
    }

    public function test_an_owner_import_still_writes_to_the_owner_and_creates_no_athlete(): void
    {
        $owner = User::factory()->stravaConnected()->create();

        $this->apply($owner, $this->plan($owner, [$this->row('2026-10-10 09:33:27')]));

        $this->assertSame(1, User::count());
        $this->assertSame(1, $owner->activities()->count());
    }

    /**
     * @param  array<int, GarminCsvRow>  $rows
     */
    private function plan(?User $athlete, array $rows): GarminImportPlan
    {
        return app(PlanGarminImport::class)($athlete, $rows);
    }

    private function apply(?User $athlete, GarminImportPlan $plan): GarminImportSummary
    {
        return app(ApplyGarminImport::class)($athlete, $plan, [], new ParsedGarminCsv(array_values($plan->newRows), [], 0, []));
    }

    private function row(string $start): GarminCsvRow
    {
        $startDateLocal = CarbonImmutable::parse($start, 'UTC');

        return new GarminCsvRow(SportType::Run, 'Paese Corsa', 8_290, 3_263, $startDateLocal, $startDateLocal->startOfDay());
    }
}
