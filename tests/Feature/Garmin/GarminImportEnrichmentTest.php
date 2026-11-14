<?php

namespace Tests\Feature\Garmin;

use App\Enums\ActivitySource;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * FR-022 on the import side, through the real upload → review → confirm → summary flow:
 * a re-import fills the extended metrics of runs already stored and reports them as
 * enriched, never replaces a stored value, and a second pass changes nothing.
 */
class GarminImportEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private const EXTENDED_HEADER = 'Tipo di attività,Data,Preferita,Titolo,Distanza,Tempo,Tempo in movimento,Calorie,Passi,Quota minima';

    /**
     * The four runs of activities-it-extended.csv: start, metres, moving seconds.
     *
     * @var array<string, array{string, int, int}>
     */
    private const FIXTURE_RUNS = [
        'full' => ['2026-10-10 09:33:27', 17_020, 5_529],
        'real tss' => ['2026-10-08 06:53:22', 12_500, 4_350],
        'elapsed only' => ['2026-10-06 07:00:00', 6_000, 2_100],
        'run metrics only' => ['2026-10-04 08:15:00', 5_000, 1_680],
    ];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow('2026-10-11 12:00:00');

        $this->owner = $this->signInOwner();
    }

    public function test_a_re_import_fills_the_extended_metrics_of_stored_runs_and_reports_them_as_enriched(): void
    {
        $runs = $this->storeFixtureRunsWithoutMetrics();
        // This run already holds everything the file records for it, so the file adds nothing.
        $runs['elapsed only']->update(['elapsed_time_seconds' => 2_100]);

        $this->upload($this->fixture('activities-it-extended.csv'));
        $review = $this->review();
        $review->assertSee('<div><dt>Clashes to decide</dt><dd>0</dd></div>', escape: false);
        $this->confirm($review)->assertRedirect(route('garmin.import.summary'));

        $this->assertSame(4, Activity::count());

        $full = $runs['full']->fresh();
        $this->assertSame(1_028, $full->calories);
        $this->assertSame(171, $full->max_heart_rate);
        $this->assertSame(3.8, $full->aerobic_training_effect);
        $this->assertSame(100.0, $full->average_stride_length_cm);
        $this->assertSame(49.8, $full->average_ground_contact_balance_left_percent);
        $this->assertSame(15_490, $full->steps);
        $this->assertSame(5_620, $full->elapsed_time_seconds);
        $this->assertSame(1_065, $full->min_elevation_meters);
        $this->assertNull($full->training_stress_score, 'A 0.0 score is not recorded.');
        $this->assertNull($full->hr_zone_1_seconds, 'The CSV carries no API-only metric.');
        $this->assertNull($full->location_name);

        $this->assertSame(48.3, $runs['real tss']->fresh()->training_stress_score);
        $this->assertSame(['pace' => null, 'ascent' => 20, 'hr' => 140], [
            'pace' => $runs['run metrics only']->fresh()->average_pace_seconds_per_km,
            'ascent' => $runs['run metrics only']->fresh()->total_ascent_meters,
            'hr' => $runs['run metrics only']->fresh()->average_heart_rate,
        ], 'The three run metrics are enriched through the same path.');

        $this->actingAs($this->owner)
            ->get(route('garmin.import.summary'))
            ->assertSee('<div><dt>Enriched</dt><dd>3</dd></div>', escape: false)
            ->assertSee('<div><dt>Already imported</dt><dd>1</dd></div>', escape: false)
            ->assertSee('3 existing runs gained missing metrics.')
            ->assertDontSee('Nothing new');
    }

    public function test_a_second_re_import_enriches_nothing_and_writes_nothing(): void
    {
        $this->storeFixtureRunsWithoutMetrics();

        $this->upload($this->fixture('activities-it-extended.csv'));
        $this->confirm($this->review());

        $snapshot = DB::table('activities')->orderBy('id')->get();
        Carbon::setTestNow('2026-10-11 12:30:00');

        $this->upload($this->fixture('activities-it-extended.csv'));
        $this->confirm($this->review());

        $this->assertEquals($snapshot, DB::table('activities')->orderBy('id')->get(), 'Values and updated_at are untouched.');
        $this->actingAs($this->owner)
            ->get(route('garmin.import.summary'))
            ->assertSee('<div><dt>Enriched</dt><dd>0</dd></div>', escape: false)
            ->assertSee('<div><dt>Already imported</dt><dd>4</dd></div>', escape: false)
            ->assertSee('Nothing new — every run was already imported.');
    }

    public function test_a_stored_value_is_never_replaced_by_a_re_import_and_only_the_null_ones_are_filled(): void
    {
        $runs = $this->storeFixtureRunsWithoutMetrics();
        $runs['full']->forceFill(['calories' => 500, 'steps' => null, 'source' => ActivitySource::GarminApi, 'garmin_activity_id' => 12_345_678_901])->save();

        $this->upload($this->fixture('activities-it-extended.csv'));
        $this->confirm($this->review());

        $full = $runs['full']->fresh();
        $this->assertSame(500, $full->calories, 'The stored calories differ from the file and stay.');
        $this->assertSame(15_490, $full->steps, 'A null metric is filled.');
        $this->assertSame(ActivitySource::GarminApi, $full->source, 'Enrichment does not change who wrote the run.');
        $this->assertSame(12_345_678_901, $full->garmin_activity_id);
    }

    public function test_a_stored_zero_is_a_value_and_is_not_filled_over(): void
    {
        $runs = $this->storeFixtureRunsWithoutMetrics();
        $runs['real tss']->update(['total_descent_meters' => 12, 'min_elevation_meters' => 5]);

        $this->upload($this->fixture('activities-it-extended.csv'));
        $this->confirm($this->review());

        $this->assertSame(12, $runs['real tss']->fresh()->total_descent_meters, 'The file says 0, the stored 12 stays.');
        $this->assertSame(5, $runs['real tss']->fresh()->min_elevation_meters);
    }

    public function test_overwrite_keeps_the_stored_extended_value_on_a_double_dash_and_takes_a_populated_cell(): void
    {
        $stored = $this->storedRun('2026-10-10 09:34:10', [
            'distance_meters' => 8_000,
            'moving_time_seconds' => 3_000,
            'calories' => 777,
            'steps' => 9_000,
            'min_elevation_meters' => 100,
            'training_stress_score' => 55.5,
            'location_name' => 'Lazise',
        ]);

        $this->upload($this->csv(self::EXTENDED_HEADER, [
            'Corsa,2026-10-10 09:33:27,false,"Paese Corsa","8.29","00:54:24","00:54:23","--","8,240","-3"',
        ]));
        $this->confirm($this->review(), [0 => 'overwrite']);

        $overwritten = $stored->fresh();
        $this->assertSame(8_290, $overwritten->distance_meters, 'The CSV fields win.');
        $this->assertSame(777, $overwritten->calories, 'A -- keeps the stored calories.');
        $this->assertSame(8_240, $overwritten->steps, 'A populated cell replaces its stored value.');
        $this->assertSame(-3, $overwritten->min_elevation_meters);
        $this->assertSame(55.5, $overwritten->training_stress_score, 'A column the file lacks keeps the stored value.');
        $this->assertSame('Lazise', $overwritten->location_name, 'The CSV never touches an API-only metric.');
        $this->assertSame(ActivitySource::GarminCsv, $overwritten->source);
    }

    public function test_the_client_sample_re_imported_over_its_own_runs_fills_the_elapsed_time_only(): void
    {
        $this->upload($this->fixture('activities-it.csv'));
        $this->confirm($this->review());
        // The runs as an earlier release stored them: no elapsed time.
        DB::table('activities')->update(['elapsed_time_seconds' => null]);

        $this->upload($this->fixture('activities-it.csv'));
        $this->confirm($this->review());

        $this->assertSame(37, Activity::whereNotNull('elapsed_time_seconds')->count());
        $this->assertSame(0, Activity::whereNotNull('calories')->count());
        $this->assertSame(0, Activity::whereNotNull('steps')->count());
        $this->assertSame(0, Activity::whereNotNull('training_stress_score')->count());
        $this->actingAs($this->owner)
            ->get(route('garmin.import.summary'))
            ->assertSee('<div><dt>Enriched</dt><dd>37</dd></div>', escape: false)
            ->assertSee('<div><dt>Already imported</dt><dd>0</dd></div>', escape: false);
    }

    public function test_the_lede_names_a_single_enriched_run_in_the_singular(): void
    {
        $this->storedRun('2026-10-10 09:33:27', ['distance_meters' => 8_290, 'moving_time_seconds' => 3_263, 'calories' => null]);

        $this->upload($this->csv(self::EXTENDED_HEADER, [
            'Corsa,2026-10-10 09:33:27,false,"Paese Corsa","8.29","00:54:24","00:54:23","512","--","--"',
        ]));
        $this->confirm($this->review());

        $this->actingAs($this->owner)
            ->get(route('garmin.import.summary'))
            ->assertSee('0 runs added, 0 overwritten. The heatmap is up to date. 1 existing run gained missing metrics.')
            ->assertSee('<div><dt>Enriched</dt><dd>1</dd></div>', escape: false);
    }

    public function test_enrichment_adds_no_query_per_row(): void
    {
        $this->storeFixtureRunsWithoutMetrics();
        $this->upload($this->fixture('activities-it-extended.csv'));
        $review = $this->review();

        DB::enableQueryLog();
        $this->confirm($review);
        $selects = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with($query['query'], 'select') && str_contains($query['query'], '"activities"'));

        // One lookup for the plan at confirm time, however many rows were enriched.
        $this->assertLessThanOrEqual(1, $selects->count());
    }

    /**
     * Store the four runs of activities-it-extended.csv as an earlier release left them:
     * same start, distance and moving time, no metric at all.
     *
     * @return array<string, Activity>
     */
    private function storeFixtureRunsWithoutMetrics(): array
    {
        $runs = [];

        foreach (self::FIXTURE_RUNS as $key => [$start, $distanceMeters, $movingSeconds]) {
            $runs[$key] = Activity::factory()->for($this->owner)->garminImported()->withoutMetrics()->create([
                'start_date_local' => $start,
                'activity_date' => substr($start, 0, 10),
                'distance_meters' => $distanceMeters,
                'moving_time_seconds' => $movingSeconds,
            ]);
        }

        return $runs;
    }

    private function upload(UploadedFile $file): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('garmin.import.store'), ['csv' => $file]);
    }

    private function review(): TestResponse
    {
        return $this->actingAs($this->owner)->get(route('garmin.import.review'))->assertOk();
    }

    /**
     * Post the review's own hidden token and fingerprint back, the way the form does,
     * with Skip for every conflict unless told otherwise.
     *
     * @param  array<int, string>|null  $resolutions
     */
    private function confirm(TestResponse $review, ?array $resolutions = null): TestResponse
    {
        $html = (string) $review->getContent();

        preg_match('/name="token" value="([^"]+)"/', $html, $token);
        preg_match('/name="fingerprint" value="([^"]+)"/', $html, $fingerprint);

        if ($resolutions === null) {
            preg_match_all('/name="resolutions\[(\d+)\]" value="skip"/', $html, $indexes);
            $resolutions = array_fill_keys(array_map('intval', $indexes[1]), 'skip');
        }

        return $this->actingAs($this->owner)->post(route('garmin.import.confirm'), [
            'token' => $token[1] ?? '',
            'fingerprint' => $fingerprint[1] ?? '',
            'resolutions' => $resolutions,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function storedRun(string $start, array $attributes = []): Activity
    {
        return Activity::factory()->for($this->owner)->garminImported()->create([
            'start_date_local' => $start,
            'activity_date' => substr($start, 0, 10),
            ...$attributes,
        ]);
    }

    private function fixture(string $name): UploadedFile
    {
        return new UploadedFile(base_path("tests/Fixtures/Garmin/{$name}"), $name, 'text/csv', null, true);
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function csv(string $header, array $lines): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('activities.csv', implode("\n", [$header, ...$lines])."\n");
    }
}
