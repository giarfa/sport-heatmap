<?php

namespace Tests\Feature\Garmin;

use App\Enums\ActivitySource;
use App\Enums\SportType;
use App\Models\Activity;
use App\Models\User;
use App\Support\Heatmap\HeatmapWindow;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The owner's upload → review → confirm → summary flow, end to end over HTTP,
 * and how its result holds up against the nightly Strava sync.
 */
class GarminImportFlowTest extends TestCase
{
    use RefreshDatabase;

    private const IT_HEADER = 'Tipo di attività,Data,Preferita,Titolo,Distanza,Tempo,Tempo in movimento';

    private const IT_METRICS_HEADER = self::IT_HEADER.',Passo medio,Ascesa totale,FC Media';

    private const SAMPLE_FIRST_ROW = 'Corsa,2026-10-10 09:33:27,false,"Paese Corsa","8.29","00:54:24","00:54:23"';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Config::set('strava.retry_backoff_ms', [0, 0, 0]);
        Carbon::setTestNow('2026-10-11 12:00:00');

        $this->owner = $this->allowlistedAthlete();
    }

    public function test_the_italian_sample_on_an_empty_database_imports_37_runs_and_ignores_the_hikes(): void
    {
        $this->upload($this->fixture('activities-it.csv'))->assertRedirect(route('garmin.import.review'));

        $review = $this->actingAs($this->owner)->get(route('garmin.import.review'));
        $review->assertOk();
        $review->assertSee('<div><dt>New runs</dt><dd>37</dd></div>', escape: false);
        $review->assertSee('<div><dt>Clashes to decide</dt><dd>0</dd></div>', escape: false);
        $this->assertSame(0, Activity::count());

        $this->confirm($review)->assertRedirect(route('garmin.import.summary'));

        $this->assertSame(37, Activity::where('source', ActivitySource::GarminCsv)->whereNull('strava_activity_id')->count());

        $first = Activity::where('start_date_local', '2026-10-10 09:33:27')->sole();
        $this->assertSame(8_290, $first->distance_meters);
        $this->assertSame(3_263, $first->moving_time_seconds);
        $this->assertSame('2026-10-10', $first->activity_date->toDateString());
        $this->assertSame(SportType::Run, $first->sport_type);

        $summary = $this->actingAs($this->owner)->get(route('garmin.import.summary'));
        $summary->assertOk();
        $summary->assertSee('37 runs added, 0 overwritten.');
        $summary->assertSee('<div><dt>Not runs, ignored</dt><dd>3</dd></div>', escape: false);
        $summary->assertSee('Escursionismo');
    }

    public function test_the_english_fixture_maps_types_and_lists_invalid_and_outside_window_rows(): void
    {
        $this->upload($this->fixture('activities-en.csv'));
        $this->confirm($this->review());

        $this->assertSame(6, Activity::count());
        $this->assertSame(SportType::TrailRun, Activity::where('name', 'Ridge Trail')->sole()->sport_type);
        $this->assertSame(SportType::VirtualRun, Activity::where('name', 'Zwift Run')->sole()->sport_type);

        $summary = $this->actingAs($this->owner)->get(route('garmin.import.summary'));
        $summary->assertSee('<div><dt>Outside 16 weeks</dt><dd>2</dd></div>', escape: false);
        $summary->assertSee('<div><dt>Unreadable rows</dt><dd>2</dd></div>', escape: false);
        $summary->assertSee('Line 7');
        $summary->assertSee('missing distance');
        $summary->assertSee('unreadable date');
        $summary->assertSee('Hiking');
    }

    public function test_a_file_that_is_not_a_csv_is_rejected_with_nothing_written(): void
    {
        $response = $this->upload(UploadedFile::fake()->createWithContent('activities.png', "\x89PNG\r\n\x1a\n\0\0\0"));

        $response->assertSessionHasErrors(['csv' => 'This is not a CSV file.']);
        $this->assertNothingPending();
    }

    public function test_a_file_larger_than_5_mb_is_rejected_with_nothing_written(): void
    {
        $response = $this->upload(UploadedFile::fake()->create('activities.csv', 5_121, 'text/csv'));

        $response->assertSessionHasErrors(['csv' => 'The file is larger than 5 MB.']);
        $this->assertNothingPending();
    }

    public function test_a_missing_file_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post(route('garmin.import.store'))
            ->assertSessionHasErrors(['csv' => 'Choose a CSV file.']);
    }

    public function test_unknown_headers_are_rejected_with_their_message_and_nothing_written(): void
    {
        $response = $this->upload($this->csv('Activity ID,Activity Date,Activity Name,Activity Type', ['1,2026-10-10,Run,Run']));

        $response->assertRedirect(route('garmin.import.create'));
        $response->assertSessionHasErrors(['csv' => 'This is not a Garmin Connect activities export. Use the Italian or English Activities CSV.']);
        $this->assertNothingPending();

        $this->actingAs($this->owner)
            ->followingRedirects()
            ->post(route('garmin.import.store'), ['csv' => $this->csv('Activity ID,Activity Date', ['1,2026-10-10'])])
            ->assertSee('aria-invalid="true"', escape: false)
            ->assertSee('aria-describedby="csv-hint csv-error"', escape: false)
            ->assertSee('<p id="csv-error" class="field-error">This is not a Garmin Connect activities export.', escape: false);
    }

    public function test_a_missing_column_is_rejected_naming_it_with_nothing_written(): void
    {
        $response = $this->upload($this->csv('Tipo di attività,Data,Preferita,Titolo,Tempo,Tempo in movimento', [
            'Corsa,2026-10-10 09:33:27,false,"Paese Corsa","00:54:24","00:54:23"',
        ]));

        $response->assertSessionHasErrors(['csv' => 'The file is missing the “Distanza” column. Export the Activities list again from Garmin Connect.']);
        $this->assertNothingPending();
    }

    public function test_a_stored_run_119_seconds_away_is_a_conflict(): void
    {
        $stored = $this->storedStravaRun('2026-10-10 09:31:28');

        $this->upload($this->csv(self::IT_HEADER, [self::SAMPLE_FIRST_ROW]));
        $review = $this->review();

        $review->assertSee('<div><dt>Clashes to decide</dt><dd>1</dd></div>', escape: false);
        $review->assertSee('<div><dt>New runs</dt><dd>0</dd></div>', escape: false);
        $review->assertSee($stored->stravaUrl());
    }

    public function test_a_stored_run_121_seconds_away_is_not_a_conflict(): void
    {
        $this->storedStravaRun('2026-10-10 09:31:26');

        $this->upload($this->csv(self::IT_HEADER, [self::SAMPLE_FIRST_ROW]));
        $review = $this->review();

        $review->assertSee('<div><dt>Clashes to decide</dt><dd>0</dd></div>', escape: false);
        $review->assertSee('<div><dt>New runs</dt><dd>1</dd></div>', escape: false);
    }

    public function test_the_review_pairs_the_csv_row_with_the_closer_of_two_stored_runs(): void
    {
        $this->storedStravaRun('2026-10-10 09:31:40', ['name' => 'Farther run']);
        $closer = $this->storedStravaRun('2026-10-10 09:34:00', ['name' => 'Closer run']);

        $this->upload($this->csv(self::IT_HEADER, [self::SAMPLE_FIRST_ROW]));
        $this->confirm($this->review(), [0 => 'overwrite']);

        $this->assertSame('Paese Corsa', $closer->fresh()->name);
        $this->assertSame('Farther run', Activity::where('name', 'Farther run')->sole()->name);
    }

    /**
     * Row A is 90 s from the stored run, row B matches it to the second. Closest
     * pairs are taken first, so B is the clash and A is new — never A claiming
     * the run and B slipping through as a duplicate.
     */
    public function test_a_later_row_that_matches_closer_wins_the_stored_run(): void
    {
        $this->storedStravaRun('2026-10-10 10:01:30');

        $this->upload($this->csv(self::IT_HEADER, [
            'Corsa,2026-10-10 10:00:00,false,"Row A","5.00","00:30:00","00:30:00"',
            'Corsa,2026-10-10 10:01:30,false,"Row B","6.00","00:36:00","00:36:00"',
        ]));

        $review = $this->review();
        $review->assertSee('<div><dt>Clashes to decide</dt><dd>1</dd></div>', escape: false);
        $review->assertSee('Row B, 10 Oct 10:01', escape: false);

        $this->confirm($review);

        $this->assertSame(2, Activity::count());
        $this->assertTrue(Activity::where('name', 'Row A')->exists());
        $this->assertFalse(Activity::where('name', 'Row B')->exists());
    }

    public function test_a_second_submit_of_the_same_review_writes_nothing_more(): void
    {
        $this->upload($this->fixture('activities-it.csv'));
        $review = $this->review();
        $pending = session('garmin_import.pending');

        $this->confirm($review)->assertRedirect(route('garmin.import.summary'));

        // The double click: a second request still carrying the pending import.
        session()->put('garmin_import.pending', $pending);

        $this->confirm($review)->assertRedirect(route('garmin.import.review'));
        $this->assertSame(37, Activity::count());
    }

    public function test_overwrite_replaces_the_csv_fields_marks_it_garmin_and_keeps_the_strava_id(): void
    {
        $stored = $this->storedStravaRun('2026-10-10 09:34:10', [
            'strava_activity_id' => 4_242,
            'name' => 'Morning Run',
            'distance_meters' => 8_150,
            'moving_time_seconds' => 3_238,
            'sport_type' => SportType::TrailRun,
        ]);

        $this->upload($this->csv(self::IT_HEADER, [self::SAMPLE_FIRST_ROW]));
        $this->confirm($this->review(), [0 => 'overwrite']);

        $overwritten = $stored->fresh();
        $this->assertSame('Paese Corsa', $overwritten->name);
        $this->assertSame(8_290, $overwritten->distance_meters);
        $this->assertSame(3_263, $overwritten->moving_time_seconds);
        $this->assertSame('2026-10-10 09:33:27', $overwritten->start_date_local->format('Y-m-d H:i:s'));
        $this->assertSame(SportType::Run, $overwritten->sport_type);
        $this->assertSame(ActivitySource::GarminCsv, $overwritten->source);
        $this->assertSame(4_242, $overwritten->strava_activity_id);
        $this->assertSame(1, Activity::count());

        $this->actingAs($this->owner)->get(route('garmin.import.summary'))->assertSee('0 runs added, 1 overwritten.');
    }

    public function test_skip_leaves_the_stored_run_byte_identical(): void
    {
        $stored = $this->storedStravaRun('2026-10-10 09:34:10');
        $before = DB::table('activities')->where('id', $stored->id)->first();

        $this->upload($this->csv(self::IT_HEADER, [self::SAMPLE_FIRST_ROW]));
        $this->confirm($this->review(), [0 => 'skip']);

        $this->assertEquals($before, DB::table('activities')->where('id', $stored->id)->first());
        $this->assertSame(1, Activity::count());
        $this->actingAs($this->owner)->get(route('garmin.import.summary'))->assertSee('<div><dt>Skipped</dt><dd>1</dd></div>', escape: false);
    }

    public function test_overwrite_all_shortcut_markup_is_rendered_hidden_for_js_to_reveal(): void
    {
        $this->storedStravaRun('2026-10-10 09:34:10');

        $this->upload($this->csv(self::IT_HEADER, [self::SAMPLE_FIRST_ROW]));

        $this->review()
            ->assertSee('<div class="shortcuts" data-resolve-shortcuts hidden>', escape: false)
            ->assertSee('data-resolve-all="overwrite"', escape: false)
            ->assertSee('name="resolutions[0]" value="skip" checked', escape: false);
    }

    public function test_a_confirm_without_an_answer_for_every_conflict_writes_nothing(): void
    {
        $stored = $this->storedStravaRun('2026-10-10 09:34:10');
        $before = DB::table('activities')->where('id', $stored->id)->first();

        $this->upload($this->csv(self::IT_HEADER, [self::SAMPLE_FIRST_ROW]));

        $this->confirm($this->review(), [])->assertRedirect(route('garmin.import.review'));

        $this->assertEquals($before, DB::table('activities')->where('id', $stored->id)->first());
        $this->assertNotNull(session('garmin_import.pending'));
    }

    public function test_abandoning_the_review_writes_nothing(): void
    {
        $this->upload($this->fixture('activities-it.csv'));
        $this->review()->assertOk();

        $this->assertSame(0, Activity::count());
    }

    public function test_cancel_clears_the_pending_import_and_writes_nothing(): void
    {
        $this->upload($this->fixture('activities-it.csv'));

        $this->actingAs($this->owner)
            ->post(route('garmin.import.cancel'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'Import cancelled. Nothing was written.');

        $this->assertNothingPending();
        $this->assertSame(0, Activity::count());
        $this->actingAs($this->owner)->get(route('garmin.import.review'))->assertRedirect(route('garmin.import.create'));
    }

    public function test_a_review_made_stale_by_a_new_stored_run_writes_nothing(): void
    {
        $this->upload($this->csv(self::IT_HEADER, [self::SAMPLE_FIRST_ROW]));
        $review = $this->review();

        $this->storedStravaRun('2026-10-10 09:33:00');

        $this->confirm($review)
            ->assertRedirect(route('garmin.import.review'))
            ->assertSessionHas('garmin_import.stale', 'Your stored activities changed since this review — check the conflicts again.');

        $this->assertSame(1, Activity::count());
        $this->assertNotNull(session('garmin_import.pending'));
        $this->review()->assertSee('Your stored activities changed since this review', escape: false);
    }

    public function test_a_review_form_from_an_earlier_upload_cannot_confirm_a_newer_one(): void
    {
        $this->upload($this->csv(self::IT_HEADER, [self::SAMPLE_FIRST_ROW]));
        $staleReview = $this->review();

        $this->upload($this->fixture('activities-it.csv'));

        $this->confirm($staleReview)->assertRedirect(route('garmin.import.review'));
        $this->assertSame(0, Activity::count());
    }

    public function test_the_italian_metric_fixture_stores_pace_ascent_and_heart_rate_per_run(): void
    {
        $this->upload($this->fixture('activities-it-metrics.csv'));
        $this->confirm($this->review());

        $first = Activity::where('start_date_local', '2026-10-10 09:33:27')->sole();
        $this->assertSame(323, $first->average_pace_seconds_per_km);
        $this->assertSame(45, $first->total_ascent_meters);
        $this->assertSame(152, $first->average_heart_rate);

        $hill = Activity::where('start_date_local', '2026-10-08 06:53:22')->sole();
        $this->assertSame(348, $hill->average_pace_seconds_per_km);
        $this->assertSame(1_234, $hill->total_ascent_meters);
        $this->assertSame(149, $hill->average_heart_rate);

        $this->assertSame(3_910, Activity::where('name', 'Passeggiata di corsa')->sole()->average_pace_seconds_per_km);
    }

    public function test_the_english_metric_fixture_stores_the_same_values(): void
    {
        $this->upload($this->fixture('activities-en-metrics.csv'));
        $this->confirm($this->review());

        $first = Activity::where('name', 'Paese Run')->sole();
        $this->assertSame(323, $first->average_pace_seconds_per_km);
        $this->assertSame(45, $first->total_ascent_meters);
        $this->assertSame(152, $first->average_heart_rate);
        $this->assertSame(1_234, Activity::where('name', 'Hill run')->sole()->total_ascent_meters);
    }

    public function test_the_verbatim_sample_stores_empty_metrics_never_zero(): void
    {
        $this->upload($this->fixture('activities-it.csv'));
        $this->confirm($this->review());

        $this->assertSame(37, Activity::count());
        $this->assertSame(37, Activity::whereNull('average_pace_seconds_per_km')
            ->whereNull('total_ascent_meters')
            ->whereNull('average_heart_rate')
            ->count());
    }

    public function test_an_unreadable_pace_imports_the_row_and_leaves_only_the_pace_empty(): void
    {
        $this->upload($this->fixture('activities-it-metrics.csv'));
        $review = $this->review();
        $review->assertSee('<div><dt>Unreadable rows</dt><dd>0</dd></div>', escape: false);
        $this->confirm($review);

        $row = Activity::where('name', 'Passo illeggibile')->sole();
        $this->assertNull($row->average_pace_seconds_per_km);
        $this->assertSame(20, $row->total_ascent_meters);
        $this->assertSame(140, $row->average_heart_rate);
        $this->assertSame(5, Activity::count());

        $this->actingAs($this->owner)
            ->get(route('garmin.import.summary'))
            ->assertSee('5 runs added, 0 overwritten.')
            ->assertSee('<div><dt>Unreadable rows</dt><dd>0</dd></div>', escape: false);
    }

    public function test_overwrite_takes_the_csv_metrics_and_keeps_a_stored_value_where_the_csv_has_none(): void
    {
        $stored = $this->storedStravaRun('2026-10-10 09:34:10', [
            'average_pace_seconds_per_km' => 331,
            'total_ascent_meters' => 62,
            'average_heart_rate' => 150,
        ]);

        $this->upload($this->csv(self::IT_METRICS_HEADER, [self::SAMPLE_FIRST_ROW.',"5:27","--","151"']));
        $this->confirm($this->review(), [0 => 'overwrite']);

        $overwritten = $stored->fresh();
        $this->assertSame(327, $overwritten->average_pace_seconds_per_km, 'The CSV pace wins.');
        $this->assertSame(62, $overwritten->total_ascent_meters, 'A -- in the CSV keeps the stored ascent.');
        $this->assertSame(151, $overwritten->average_heart_rate, 'The CSV heart rate wins.');
        $this->assertSame(ActivitySource::GarminCsv, $overwritten->source);
    }

    public function test_overwrite_with_no_metric_columns_keeps_every_stored_metric(): void
    {
        $stored = $this->storedStravaRun('2026-10-10 09:34:10', [
            'average_pace_seconds_per_km' => 331,
            'total_ascent_meters' => 62,
            'average_heart_rate' => 150,
        ]);

        $this->upload($this->csv(self::IT_HEADER, [self::SAMPLE_FIRST_ROW]));
        $this->confirm($this->review(), [0 => 'overwrite']);

        $overwritten = $stored->fresh();
        $this->assertSame(331, $overwritten->average_pace_seconds_per_km);
        $this->assertSame(62, $overwritten->total_ascent_meters);
        $this->assertSame(150, $overwritten->average_heart_rate);
    }

    public function test_an_already_imported_run_gains_its_missing_metrics_and_keeps_the_ones_it_has(): void
    {
        $imported = Activity::factory()->for($this->owner)->garminImported()->withoutMetrics()->create([
            'name' => 'Paese Corsa',
            'start_date_local' => '2026-10-10 09:33:27',
            'activity_date' => '2026-10-10',
            'distance_meters' => 8_290,
            'moving_time_seconds' => 3_263,
            'average_heart_rate' => 140,
        ]);

        $this->upload($this->csv(self::IT_METRICS_HEADER, [self::SAMPLE_FIRST_ROW.',"5:23","45","152"']));
        $review = $this->review();
        $review->assertSee('<div><dt>Already imported</dt><dd>1</dd></div>', escape: false);
        $this->confirm($review);

        $backfilled = $imported->fresh();
        $this->assertSame(323, $backfilled->average_pace_seconds_per_km);
        $this->assertSame(45, $backfilled->total_ascent_meters);
        $this->assertSame(140, $backfilled->average_heart_rate, 'A heart rate already stored is never replaced by a re-import.');
        $this->assertSame(1, Activity::count());

        $this->actingAs($this->owner)
            ->get(route('garmin.import.summary'))
            ->assertSee('<div><dt>Already imported</dt><dd>1</dd></div>', escape: false);
    }

    public function test_a_backfill_only_import_forgets_the_cached_grid(): void
    {
        Activity::factory()->for($this->owner)->garminImported()->withoutMetrics()->create([
            'name' => 'Paese Corsa',
            'start_date_local' => '2026-10-10 09:33:27',
            'activity_date' => '2026-10-10',
            'distance_meters' => 8_290,
            'moving_time_seconds' => 3_263,
        ]);
        $cacheKey = "heatmap:grid:v2:{$this->owner->id}:".HeatmapWindow::current()->startsOn->toDateString().':2026-10-11';

        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();
        $this->assertTrue(Cache::has($cacheKey));

        $this->upload($this->csv(self::IT_METRICS_HEADER, [self::SAMPLE_FIRST_ROW.',"5:23","45","152"']));
        $this->confirm($this->review());

        $this->assertFalse(Cache::has($cacheKey));
    }

    public function test_re_importing_a_metric_file_changes_nothing(): void
    {
        $this->upload($this->fixture('activities-it-metrics.csv'));
        $this->confirm($this->review());

        $snapshot = DB::table('activities')->orderBy('id')->get();
        Carbon::setTestNow('2026-10-11 12:30:00');

        $this->upload($this->fixture('activities-it-metrics.csv'));
        $review = $this->review();
        $review->assertSee('<div><dt>Already imported</dt><dd>5</dd></div>', escape: false);
        $this->confirm($review);

        $this->assertEquals($snapshot, DB::table('activities')->orderBy('id')->get(), 'Values and updated_at are untouched.');
    }

    public function test_the_review_shows_the_stored_and_the_csv_metrics_side_by_side(): void
    {
        $this->storedStravaRun('2026-10-10 09:34:10', [
            'average_pace_seconds_per_km' => 331,
            'total_ascent_meters' => 62,
            'average_heart_rate' => 150,
        ]);

        $this->upload($this->csv(self::IT_METRICS_HEADER, [self::SAMPLE_FIRST_ROW.',"5:27","64","--"']));
        $review = $this->review();

        $review->assertSeeInOrder(['Avg pace', '5:31 /km', 'Ascent', '62 m', 'Heart rate', '150 bpm']);
        $review->assertSeeInOrder(['Avg pace', '5:27 /km', 'Ascent', '64 m', 'Heart rate']);
        $review->assertSee('<dd class="is-empty"><span aria-hidden="true">—</span><span class="sr-only">not recorded</span></dd>', escape: false);
    }

    public function test_the_review_prints_an_empty_stored_metric_as_a_dash_not_a_zero(): void
    {
        $this->storedStravaRun('2026-10-10 09:34:10', [
            'average_pace_seconds_per_km' => null,
            'total_ascent_meters' => null,
            'average_heart_rate' => null,
        ]);

        $this->upload($this->csv(self::IT_HEADER, [self::SAMPLE_FIRST_ROW]));
        $review = $this->review();

        $this->assertSame(6, substr_count((string) $review->getContent(), 'class="is-empty"'));
        $review->assertDontSee('0 bpm');
        $review->assertDontSee('0 m');
    }

    public function test_re_importing_the_same_file_changes_nothing(): void
    {
        $this->upload($this->fixture('activities-it.csv'));
        $this->confirm($this->review());

        $snapshot = DB::table('activities')->orderBy('id')->get();

        $this->upload($this->fixture('activities-it.csv'));
        $review = $this->review();
        $review->assertSee('<div><dt>Already imported</dt><dd>37</dd></div>', escape: false);
        $review->assertSee('<div><dt>Clashes to decide</dt><dd>0</dd></div>', escape: false);
        $this->confirm($review);

        $this->assertEquals($snapshot, DB::table('activities')->orderBy('id')->get());
        $this->actingAs($this->owner)
            ->get(route('garmin.import.summary'))
            ->assertSee('Nothing new — every run was already imported.')
            ->assertSee('<div><dt>Already imported</dt><dd>37</dd></div>', escape: false);
    }

    public function test_planning_runs_a_single_activities_query_whatever_the_row_count(): void
    {
        $this->upload($this->fixture('activities-it.csv'));

        DB::enableQueryLog();
        $this->review();
        $activityQueries = array_filter(
            DB::getQueryLog(),
            fn (array $query): bool => str_contains($query['query'], 'from "activities"'),
        );

        $this->assertCount(1, $activityQueries);
    }

    public function test_confirming_forgets_the_cached_grid_for_today(): void
    {
        $cacheKey = "heatmap:grid:v2:{$this->owner->id}:".HeatmapWindow::current()->startsOn->toDateString().':2026-10-11';

        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();
        $this->assertTrue(Cache::has($cacheKey));

        $this->upload($this->fixture('activities-it.csv'));
        $this->confirm($this->review());

        $this->assertFalse(Cache::has($cacheKey));
    }

    public function test_the_raw_upload_is_never_persisted(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $this->upload($this->fixture('activities-it.csv'));
        $this->confirm($this->review());

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_garmin_won_twin_survives_a_sync_in_which_strava_still_returns_it(): void
    {
        $stored = $this->storedStravaRun('2026-10-10 09:34:10', [
            'strava_activity_id' => 4_242,
            'name' => 'Morning Run',
            'distance_meters' => 8_150,
        ]);

        $this->upload($this->csv(self::IT_HEADER, [self::SAMPLE_FIRST_ROW]));
        $this->confirm($this->review(), [0 => 'overwrite']);

        Http::fake([
            'www.strava.com/api/v3/athlete/activities*' => Http::response([[
                'id' => 4_242,
                'sport_type' => 'Run',
                'name' => 'Morning Run',
                'distance' => 8_150.0,
                'moving_time' => 3_238,
                'start_date_local' => '2026-10-10T09:34:10Z',
            ]]),
        ]);

        $this->artisan('strava:sync')->assertExitCode(Command::SUCCESS);

        $this->assertSame(1, Activity::count());
        $twin = $stored->fresh();
        $this->assertSame(ActivitySource::GarminCsv, $twin->source);
        $this->assertSame('Paese Corsa', $twin->name);
        $this->assertSame(8_290, $twin->distance_meters);
        $this->assertSame('2026-10-10 09:33:27', $twin->start_date_local->format('Y-m-d H:i:s'));
    }

    public function test_the_review_links_stored_strava_runs_to_strava_and_garmin_runs_nowhere(): void
    {
        $this->storedStravaRun('2026-10-10 09:34:10', ['strava_activity_id' => 4_242]);
        Activity::factory()->for($this->owner)->garminImported()->create([
            'start_date_local' => '2026-10-08 06:54:00',
            'activity_date' => '2026-10-08',
        ]);

        $this->upload($this->fixture('activities-it.csv'));
        $review = $this->review();

        $review->assertSee('<div><dt>Clashes to decide</dt><dd>2</dd></div>', escape: false);
        $review->assertSee('href="https://www.strava.com/activities/4242"', escape: false);
        $this->assertSame(1, substr_count($review->getContent(), 'https://www.strava.com/activities/'));
        $review->assertSee('Garmin import');
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
     * Post the review's own hidden token and fingerprint back, the way the form
     * does, with Skip for every conflict unless told otherwise.
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
    private function storedStravaRun(string $start, array $attributes = []): Activity
    {
        return Activity::factory()->for($this->owner)->create([
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

    private function assertNothingPending(): void
    {
        $this->assertNull(session('garmin_import.pending'));
        $this->assertSame(0, Activity::count());
    }
}
