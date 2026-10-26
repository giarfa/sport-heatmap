<?php

namespace Tests\Feature\Dashboard;

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
 * The day cell detail (FR-011): which days open one, what each run shows, that a
 * opening the page writes and sends nothing (FR-017), and that it agrees with the grid
 * after an import because it rides in the same cached grid.
 */
class DayDetailTest extends TestCase
{
    use RefreshDatabase;

    private const IT_METRICS_HEADER = 'Tipo di attività,Data,Preferita,Titolo,Distanza,Tempo,Tempo in movimento,Passo medio,Ascesa totale,FC Media';

    private User $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo('2026-09-05 09:00:00'); // Saturday

        $this->athlete = $this->signInOwner();
    }

    public function test_a_ran_day_renders_a_button_that_opens_its_detail(): void
    {
        $this->storeRun('2026-09-03', 8_200);

        $html = (string) $this->getDashboard()->getContent();

        $this->assertStringContainsString('<button type="button" class="hm-hit" data-day="2026-09-03" aria-haspopup="dialog"', $html);
        $this->assertStringContainsString('Thursday 3 Sep 2026: 8.2 km, 1 run. Show details', $html);
        $this->assertStringContainsString('<template id="day-2026-09-03">', $html);
        $this->assertStringContainsString('id="day-dialog"', $html);
    }

    public function test_rest_and_future_days_render_no_button_and_no_detail(): void
    {
        $this->storeRun('2026-09-03', 8_200);

        $html = (string) $this->getDashboard()->getContent();

        $this->assertSame(1, substr_count($html, 'data-day="'));
        $this->assertSame(1, substr_count($html, '<template id="day-'));
        $this->assertStringContainsString('Friday 4 Sep 2026: rest day, 0 km', $html);
        $this->assertStringContainsString('Sunday 6 Sep 2026: not yet', $html);
        $this->assertStringNotContainsString('data-day="2026-09-04"', $html);
        $this->assertStringNotContainsString('data-day="2026-09-06"', $html);
    }

    public function test_a_page_without_runs_carries_no_detail_markup_beyond_the_empty_dialog(): void
    {
        $html = (string) $this->getDashboard()->getContent();

        $this->assertStringNotContainsString('data-day="', $html);
        $this->assertStringNotContainsString('<template id="day-', $html);
    }

    public function test_a_runs_detail_lists_its_values_with_no_link_and_no_source_label(): void
    {
        $this->storeRun('2026-09-03', 8_290, [
            'name' => 'Paese Corsa',
            'moving_time_seconds' => 2_677,
            'average_pace_seconds_per_km' => 323,
            'total_ascent_meters' => 45,
            'average_heart_rate' => 152,
        ], 'garminImported');

        $detail = $this->detailOf($this->getDashboard(), '2026-09-03');

        $this->assertStringContainsString('Thu 3 Sep 2026', $detail);
        $this->assertStringContainsString('1 run · 8.3 km', $detail);
        $this->assertStringContainsString('Paese Corsa', $detail);
        $this->assertStringContainsString('<dd>8.3 km</dd>', $detail);
        $this->assertStringContainsString('<dd>0:44:37</dd>', $detail);
        $this->assertStringContainsString('<dd>5:23 /km</dd>', $detail);
        $this->assertStringContainsString('<dd>45 m</dd>', $detail);
        $this->assertStringContainsString('<dd>152 bpm</dd>', $detail);
        $this->assertStringNotContainsString('href=', $detail);
        $this->assertStringNotContainsStringIgnoringCase('strava', $detail);
        $this->assertStringNotContainsString('Garmin import', $detail);
    }

    public function test_missing_metrics_read_as_a_dash_never_as_zero(): void
    {
        $this->storeRun('2026-09-03', 8_290, [], 'withoutMetrics');
        $this->storeRun('2026-09-02', 5_000, ['average_pace_seconds_per_km' => 350, 'total_ascent_meters' => 12], 'withoutHeartRate');

        $response = $this->getDashboard();

        $none = $this->detailOf($response, '2026-09-03');
        $this->assertSame(3, substr_count($none, '<dd class="is-empty"><span aria-hidden="true">—</span><span class="sr-only">not recorded</span></dd>'));
        $this->assertStringNotContainsString(' bpm', $none);
        $this->assertStringNotContainsString('/km', $none);
        $this->assertStringNotContainsString('<dd>0 m</dd>', $none);
        $this->assertStringNotContainsString('<dd>0 bpm</dd>', $none);

        $noHeartRate = $this->detailOf($response, '2026-09-02');
        $this->assertStringContainsString('<dd>5:50 /km</dd>', $noHeartRate);
        $this->assertStringContainsString('<dd>12 m</dd>', $noHeartRate);
        $this->assertSame(1, substr_count($noHeartRate, 'class="is-empty"'));
        $this->assertStringNotContainsString('0 bpm', $noHeartRate);
    }

    public function test_a_day_with_two_runs_lists_both_in_start_order_and_sums_them(): void
    {
        $this->storeRun('2026-09-03', 5_000, ['name' => 'Evening shakeout', 'start_date_local' => '2026-09-03 18:30:00']);
        $this->storeRun('2026-09-03', 8_000, ['name' => 'Morning run', 'start_date_local' => '2026-09-03 07:15:00']);

        $detail = $this->detailOf($this->getDashboard(), '2026-09-03');

        $this->assertStringContainsString('2 runs · 13.0 km', $detail);
        $this->assertSame(2, substr_count($detail, 'class="run"'));
        $this->assertLessThan(strpos($detail, 'Evening shakeout'), strpos($detail, 'Morning run'));
    }

    public function test_a_run_name_is_escaped(): void
    {
        $this->storeRun('2026-09-03', 5_000, ['name' => '<script>alert(1)</script>']);

        $detail = $this->detailOf($this->getDashboard(), '2026-09-03');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $detail);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $detail);
    }

    public function test_the_detail_shows_heart_rate_and_opening_the_page_writes_nothing(): void
    {
        $this->storeRun('2026-09-03', 8_290, ['average_pace_seconds_per_km' => 323, 'total_ascent_meters' => 45, 'average_heart_rate' => 152]);
        $this->getDashboard(); // the first build, so the request below is a pure read
        $before = $this->rowCounts();

        $detail = $this->detailOf($this->getDashboard(), '2026-09-03');
        $this->assertStringContainsString('<dd>152 bpm</dd>', $detail);
        $this->assertStringContainsString('<dd>5:23 /km</dd>', $detail);
        $this->assertSame($before, $this->rowCounts(), 'Opening the page writes no domain row.');
        Http::assertNothingSent();
    }

    public function test_a_backfill_only_import_updates_the_detail(): void
    {
        Activity::factory()->for($this->athlete)->garminImported()->withoutMetrics()->create([
            'name' => 'Paese Corsa',
            'start_date_local' => '2026-09-03 09:33:27',
            'activity_date' => '2026-09-03',
            'distance_meters' => 8_290,
            'moving_time_seconds' => 3_263,
        ]);

        $before = $this->detailOf($this->getDashboard(), '2026-09-03');
        $this->assertStringNotContainsString('152 bpm', $before);

        $this->importCsv('Corsa,2026-09-03 09:33:27,false,"Paese Corsa","8.29","00:54:24","00:54:23","6:33","45","152"');

        $after = $this->detailOf($this->getDashboard(), '2026-09-03');
        $this->assertStringContainsString('<dd>6:33 /km</dd>', $after);
        $this->assertStringContainsString('<dd>45 m</dd>', $after);
        $this->assertStringContainsString('<dd>152 bpm</dd>', $after);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function storeRun(string $date, int $meters, array $attributes = [], ?string $state = null): Activity
    {
        $factory = Activity::factory()->for($this->athlete)->onDate(Carbon::parse($date));

        if ($state !== null) {
            $factory = $factory->{$state}();
        }

        return $factory->create([
            'start_date_local' => "{$date} 07:15:00",
            'activity_date' => $date,
            'distance_meters' => $meters,
            'moving_time_seconds' => (int) round($meters / 1_000 * 323),
            ...$attributes,
        ]);
    }

    private function getDashboard(): TestResponse
    {
        return $this->get('/')->assertOk();
    }

    /**
     * The inert template of one day: what the dialog is filled with.
     */
    private function detailOf(TestResponse $response, string $date): string
    {
        $matched = preg_match('#<template id="day-'.preg_quote($date, '#').'">(.*?)</template>#s', (string) $response->getContent(), $parts);

        $this->assertSame(1, $matched, "No detail template for {$date}.");

        return $parts[1];
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return [
            'users' => DB::table('users')->count(),
            'activities' => DB::table('activities')->count(),
        ];
    }

    /**
     * Upload and confirm a one-row Garmin export as the owner, the way
     * the review form does.
     */
    private function importCsv(string $row): void
    {
        $file = UploadedFile::fake()->createWithContent('activities.csv', self::IT_METRICS_HEADER."\n{$row}\n");

        $this->post(route('garmin.import.store'), ['csv' => $file])->assertRedirect(route('garmin.import.review'));

        $html = (string) $this->get(route('garmin.import.review'))->assertOk()->getContent();
        preg_match('/name="token" value="([^"]+)"/', $html, $token);
        preg_match('/name="fingerprint" value="([^"]+)"/', $html, $fingerprint);

        $this->post(route('garmin.import.confirm'), [
            'token' => $token[1] ?? '',
            'fingerprint' => $fingerprint[1] ?? '',
        ])->assertRedirect(route('garmin.import.summary'));
    }
}
