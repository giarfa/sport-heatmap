<?php

namespace Tests\Feature\Dashboard;

use App\Models\Activity;
use App\Models\User;
use App\Support\Heatmap\HeatmapGridCache;
use App\Support\Heatmap\HeatmapWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The aerobic-efficiency card on the real dashboard request: where it sits, what each
 * state prints, how it follows window navigation, that an import or a sync refreshes it,
 * that rendering writes nothing, and its accessible structure.
 */
class AerobicEfficiencyTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_URL = 'https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token';

    private const SEARCH_URL = 'https://personal.connectapi.garmin.com/activitylist-service/activities/search/activities*';

    private const IT_METRICS_HEADER = 'Tipo di attività,Data,Preferita,Titolo,Distanza,Tempo,Tempo in movimento,Passo medio,Ascesa totale,FC Media';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo('2026-11-14 09:00:00'); // Saturday
    }

    public function test_the_card_sits_in_the_rail_after_weekly_distance_with_its_headline(): void
    {
        $athlete = $this->signInOwner();
        $this->flatRuns($athlete, range(8, 15));

        $html = $this->html();

        $weekly = strpos($html, 'Weekly distance');
        $card = strpos($html, 'card p-4 trend eff');
        $import = strpos($html, '>Import</h3>') ?: strpos($html, '>Sync</h3>');

        $this->assertNotFalse($weekly);
        $this->assertGreaterThan($weekly, $card);
        $this->assertGreaterThan($card, $import);
        $this->assertStringContainsString('<h3 id="eff-heading" class="trend-title">Aerobic efficiency</h3>', $html);
        $this->assertStringContainsString('<strong>5:30</strong><span class="eff-unit"> /km</span> <span class="eff-at">at 148 bpm</span>', $html);
    }

    public function test_the_median_heart_rate_of_the_window_names_the_headline_title_and_caption(): void
    {
        $athlete = $this->signInOwner();
        $this->flatRuns($athlete, range(8, 15));

        $card = $this->card();

        $this->assertStringContainsString('at 148 bpm', $card);
        $this->assertStringContainsString('<title id="eff-svg-title">Pace at 148 bpm per run, ', $card);
        $this->assertStringContainsString('<caption class="sr-only">Pace at 148 bpm by week, ', $card);
        $this->assertStringContainsString("148 bpm is this window's median heart rate", html_entity_decode($card, ENT_QUOTES));
    }

    public function test_navigating_to_an_earlier_window_recomputes_the_reference_the_points_and_last_year(): void
    {
        $athlete = $this->signInOwner();
        $window = HeatmapWindow::current();
        $this->flatRuns($athlete, range(8, 15));
        $this->flatRuns($athlete, range(-8, -1), baseHeartRate: 160, pace: 300);
        $this->flatRuns($athlete, range(-8, -1), baseHeartRate: 160, pace: 330, yearsBack: true);

        $earlier = $window->previous()->previous();
        $current = $this->card();
        $past = $this->card('/?to='.$earlier->endsOn->toDateString());

        $this->assertStringContainsString('at 148 bpm', $current);
        $this->assertStringContainsString('<strong>5:30</strong>', $current);
        $this->assertStringNotContainsString('Same weeks last year', $current);

        $this->assertStringContainsString('at 160 bpm', $past);
        $this->assertStringContainsString('<strong>5:00</strong>', $past);
        $this->assertStringContainsString('Same weeks last year', $past);
        $this->assertStringNotContainsString('at 148 bpm', $past);
    }

    public function test_fewer_than_eight_qualifying_runs_states_the_count_and_draws_no_chart(): void
    {
        $athlete = $this->signInOwner();
        $this->flatRuns($athlete, [13, 14], runsPerWeek: 3);

        $card = $this->card();

        $this->assertStringContainsString('Not enough runs to measure efficiency: 6 in this window, 8 needed.', $card);
        $this->assertStringNotContainsString('<svg', $card);
        $this->assertStringNotContainsString('eff-headline', $card);
    }

    public function test_a_navigated_window_with_no_stored_run_shows_the_empty_sentence(): void
    {
        $athlete = $this->signInOwner();
        $this->flatRuns($athlete, range(8, 15));

        $card = $this->card('/?to='.HeatmapWindow::current()->previous()->previous()->previous()->endsOn->toDateString());

        $this->assertStringContainsString('No runs in this window, so there is nothing to chart.', $card);
        $this->assertStringNotContainsString('<svg', $card);
    }

    public function test_without_runs_a_year_earlier_there_is_no_last_year_key_or_column(): void
    {
        $athlete = $this->signInOwner();
        $this->flatRuns($athlete, range(8, 15));

        $card = $this->card();

        $this->assertStringNotContainsString('Same weeks last year', $card);
        $this->assertStringNotContainsString('Last year', $card);
        $this->assertStringNotContainsString('eff-lastyear', $card);
    }

    public function test_with_runs_a_year_earlier_last_year_is_drawn_dashed_listed_and_keyed(): void
    {
        $athlete = $this->signInOwner();
        $this->flatRuns($athlete, range(8, 15));
        $this->flatRuns($athlete, range(8, 15), pace: 350, yearsBack: true);

        $card = $this->card();

        $this->assertStringContainsString('<polyline class="eff-lastyear"', $card);
        $this->assertStringContainsString('Same weeks last year', $card);
        $this->assertStringContainsString('<th scope="col">Last year</th>', $card);
    }

    public function test_a_run_without_pace_or_heart_rate_is_never_drawn_and_never_reads_as_zero(): void
    {
        $athlete = $this->signInOwner();
        $window = HeatmapWindow::current();
        $this->flatRuns($athlete, range(8, 15));
        $drawn = substr_count($this->card(), 'class="eff-dot eff-dot--');

        Activity::factory()->for($athlete)->onDate($window->startsOn->addWeeks(15)->addDay())->state(['distance_meters' => 8_000])->create(['average_pace_seconds_per_km' => null]);
        Activity::factory()->for($athlete)->onDate($window->startsOn->addWeeks(15)->addDays(3))->state(['distance_meters' => 8_000])->create(['average_heart_rate' => null]);
        $this->app->make(HeatmapGridCache::class)->forgetAthlete($athlete);

        $card = $this->card();

        $this->assertSame($drawn, substr_count($card, 'class="eff-dot eff-dot--'));
        $this->assertStringNotContainsString('0:00', $card);
        $this->assertStringNotContainsString(' 0 bpm', $card);
    }

    public function test_a_confirmed_csv_import_refreshes_a_window_the_page_had_cached(): void
    {
        $athlete = $this->signInOwner();
        $this->flatRuns($athlete, range(8, 15));
        $before = substr_count($this->card(), 'class="eff-dot eff-dot--');

        $csv = UploadedFile::fake()->createWithContent('activities.csv', implode("\n", [
            self::IT_METRICS_HEADER,
            'Corsa,2026-11-13 08:00:00,false,"Paese Corsa","8.29","00:44:24","00:44:23","5:21","0","149"',
        ])."\n");

        $this->actingAs($athlete)->post(route('garmin.import.store'), ['csv' => $csv]);
        $review = $this->actingAs($athlete)->get(route('garmin.import.review'))->assertOk();
        $html = (string) $review->getContent();
        preg_match('/name="token" value="([^"]+)"/', $html, $token);
        preg_match('/name="fingerprint" value="([^"]+)"/', $html, $fingerprint);
        $this->actingAs($athlete)->post(route('garmin.import.confirm'), [
            'token' => $token[1] ?? '',
            'fingerprint' => $fingerprint[1] ?? '',
            'resolutions' => [],
        ]);

        $this->assertSame($before + 1, substr_count($this->card(), 'class="eff-dot eff-dot--'));
    }

    public function test_a_sync_that_writes_refreshes_a_window_the_page_had_cached(): void
    {
        $athlete = $this->signInOwner();
        $this->flatRuns($athlete, range(8, 15));
        $before = substr_count($this->card(), 'class="eff-dot eff-dot--');

        Config::set('services.garmin.token_cache_store', 'array');
        Config::set('services.garmin.api_key', 'GCDC_efficiency-test-key');
        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $token = $encode('{"alg":"none"}').'.'.$encode((string) json_encode(['exp' => now()->addDay()->getTimestamp()])).'.'.$encode('signature');

        Http::fake([
            self::TOKEN_URL => Http::response($token),
            self::SEARCH_URL => Http::response([[
                'activityId' => 5001,
                'activityName' => 'Paese Corsa',
                'activityType' => ['typeKey' => 'running'],
                'startTimeLocal' => '2026-11-13 08:00:00',
                'distance' => 8290.4,
                'movingDuration' => 2663.7,
                'averageSpeed' => 3.11,
                'elevationGain' => 4.0,
                'averageHR' => 148.6,
            ]]),
        ]);

        $this->actingAs($athlete)->post(route('garmin.sync'))->assertRedirect(route('dashboard'));

        $this->assertSame($before + 1, substr_count($this->card(), 'class="eff-dot eff-dot--'));
    }

    public function test_rendering_the_card_writes_nothing(): void
    {
        $athlete = $this->signInOwner();
        $this->flatRuns($athlete, range(8, 15));

        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->get('/')->assertOk();
        $this->get('/?to='.HeatmapWindow::current()->previous()->endsOn->toDateString())->assertOk();

        $this->assertSame([], $writes);
        Http::assertNothingSent();
    }

    public function test_the_markup_names_the_chart_lists_the_weeks_and_shapes_every_kind_of_run(): void
    {
        $athlete = $this->signInOwner();
        $window = HeatmapWindow::current();
        $this->flatRuns($athlete, range(8, 15));
        $this->flatRuns($athlete, range(8, 15), pace: 350, yearsBack: true);

        $monday = $window->startsOn->addWeeks(15);
        Activity::factory()->for($athlete)->onDate($monday->addDay())->state(['distance_meters' => 10_000, 'moving_time_seconds' => 3_400, 'average_pace_seconds_per_km' => 340])->hilly()->atHeartRate(148)->create();
        Activity::factory()->for($athlete)->onDate($monday->addDays(3))->state(['distance_meters' => 10_000, 'moving_time_seconds' => 3_400, 'average_pace_seconds_per_km' => 340])->steep()->atHeartRate(148)->create();

        $card = $this->card();

        $this->assertStringContainsString('role="img" aria-labelledby="eff-svg-title"', $card);
        $this->assertMatchesRegularExpression('#<title id="eff-svg-title">[^<]+</title>#', $card);
        $this->assertStringContainsString('<caption class="sr-only">', $card);
        $this->assertSame(16, substr_count($card, '<th scope="row">'));
        $this->assertStringContainsString('eff-dot--flat', $card);
        $this->assertStringContainsString('eff-dot--hilly', $card);
        $this->assertStringContainsString('eff-dot--steep', $card);
        $this->assertStringContainsString('Hilly run, adjusted', $card);
        $this->assertStringContainsString('Steep run, not counted', $card);
        $this->assertStringContainsString('eff-lastyear', $card);
        $this->assertStringContainsString('<summary>Weekly figures</summary>', $card);
    }

    /**
     * Three flat runs a week on Monday, Wednesday and Friday at the base heart rate, two
     * beats under it and two over it, so the median heart rate is the base. The distance
     * goes in as a state so the climb the flat state derives belongs to that distance.
     *
     * @param  array<int, int>  $weeks  window week positions, negative for weeks before the window
     */
    private function flatRuns(User $athlete, array $weeks, int $baseHeartRate = 148, int $pace = 330, bool $yearsBack = false, int $runsPerWeek = 3): void
    {
        $firstWeek = $yearsBack ? HeatmapWindow::current()->startsOn->subWeeks(52) : HeatmapWindow::current()->startsOn;
        $offsets = [-2, 0, 2];

        foreach ($weeks as $week) {
            foreach (array_slice([0, 2, 4], 0, $runsPerWeek) as $index => $weekday) {
                Activity::factory()
                    ->for($athlete)
                    ->onDate(CarbonImmutable::instance($firstWeek)->addWeeks($week)->addDays($weekday))
                    ->state(['distance_meters' => 8_000, 'moving_time_seconds' => (int) round(8 * $pace), 'average_pace_seconds_per_km' => $pace])
                    ->flat()
                    ->atHeartRate($baseHeartRate + $offsets[$index])
                    ->create();
            }
        }
    }

    private function html(string $url = '/'): string
    {
        return $this->get($url)->assertOk()->getContent() ?: '';
    }

    /**
     * The efficiency card's markup, or '' when the page has none.
     */
    private function card(string $url = '/'): string
    {
        return preg_match('/<section class="card p-4 trend eff".*?<\/section>/s', $this->html($url), $match) === 1 ? $match[0] : '';
    }
}
