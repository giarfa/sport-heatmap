<?php

namespace Tests\Feature;

use App\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * US-021: Strava is gone and the Garmin CSV import is the only way a run enters.
 * One file pins the whole acceptance matrix, so a Strava route, key, mark or
 * warning cannot quietly come back. The schema half is RemoveStravaMigrationTest;
 * the empty schedule and the missing command are ScheduleTest.
 */
class StravaRemovedTest extends TestCase
{
    use RefreshDatabase;

    private const IT_HEADER = 'Tipo di attività,Data,Preferita,Titolo,Distanza,Tempo,Tempo in movimento';

    private const SAMPLE_ROW = 'Corsa,2026-09-03 09:33:27,false,"Paese Corsa","8.29","00:54:24","00:54:23"';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo('2026-09-05 09:00:00'); // Saturday
    }

    public function test_no_route_mentions_strava_and_the_old_oauth_urls_are_gone(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $this->assertStringNotContainsStringIgnoringCase('strava', $route->uri().' '.$route->getName());
        }

        $this->signInOwner();

        $this->get('/auth/strava/redirect')->assertNotFound();
        $this->get('/auth/strava/callback')->assertNotFound();
    }

    public function test_there_is_no_strava_config_and_the_env_example_has_no_strava_key(): void
    {
        $this->assertNull(config('strava'));
        $this->assertStringNotContainsString('STRAVA_', (string) file_get_contents(base_path('.env.example')));
    }

    public function test_no_signed_in_or_signed_out_page_renders_strava(): void
    {
        $this->get(route('login'))->assertOk();
        $this->assertNoStrava($this->get(route('login')));

        $owner = $this->signInOwner();

        $this->assertNoStrava($this->get('/'));

        Activity::factory()->for($owner)->create(['start_date_local' => '2026-09-03 07:00:00', 'activity_date' => '2026-09-03']);
        Activity::factory()->for($owner)->create(['start_date_local' => '2026-09-03 18:00:00', 'activity_date' => '2026-09-03']);

        $this->assertNoStrava($this->get('/'));
        $this->assertNoStrava($this->get('/?to=2026-08-09'));
        $this->assertNoStrava($this->get(route('garmin.import.create')));

        $this->upload(self::SAMPLE_ROW);
        $review = $this->get(route('garmin.import.review'))->assertOk();
        $this->assertNoStrava($review);

        $this->confirm($review);
        $this->assertNoStrava($this->get(route('garmin.import.summary'))->assertOk());
    }

    public function test_a_conflict_review_carries_no_strava_link(): void
    {
        $owner = $this->signInOwner();
        Activity::factory()->for($owner)->create([
            'name' => 'Morning Run',
            'start_date_local' => '2026-09-03 09:31:27',
            'activity_date' => '2026-09-03',
        ]);

        $this->upload(self::SAMPLE_ROW);
        $review = $this->get(route('garmin.import.review'))->assertOk();

        $review->assertSee('<div><dt>Clashes to decide</dt><dd>1</dd></div>', escape: false);
        $this->assertNoStrava($review);
    }

    public function test_the_status_line_follows_the_last_confirmed_import_and_a_no_op_reimport_does_not_move_it(): void
    {
        $this->signInOwner();

        $this->get('/')->assertSee('No runs imported yet');

        $this->upload(self::SAMPLE_ROW);
        $this->confirm($this->get(route('garmin.import.review'))->assertOk());

        $stamp = Activity::query()->max('updated_at');
        $this->get('/')->assertSee('Last import')->assertSee('seconds ago');

        $this->travelTo('2026-09-05 11:00:00');
        $this->upload(self::SAMPLE_ROW);
        $review = $this->get(route('garmin.import.review'))->assertOk();
        $review->assertSee('<div><dt>Already imported</dt><dd>1</dd></div>', escape: false);
        $this->confirm($review);

        $this->assertSame($stamp, Activity::query()->max('updated_at'));
        $page = $this->get('/');
        $page->assertSee('Last import 2 hours ago');
        $this->assertStringNotContainsString('role="alert"', (string) $page->getContent());
    }

    public function test_a_row_two_minutes_from_a_stored_run_is_a_conflict_and_three_minutes_is_new(): void
    {
        $owner = $this->signInOwner();
        Activity::factory()->for($owner)->create(['start_date_local' => '2026-09-03 09:31:27', 'activity_date' => '2026-09-03']);

        $this->upload(self::SAMPLE_ROW);
        $this->get(route('garmin.import.review'))
            ->assertSee('<div><dt>Clashes to decide</dt><dd>1</dd></div>', escape: false)
            ->assertSee('<div><dt>New runs</dt><dd>0</dd></div>', escape: false);

        Activity::query()->delete();
        Activity::factory()->for($owner)->create(['start_date_local' => '2026-09-03 09:30:27', 'activity_date' => '2026-09-03']);

        $this->upload(self::SAMPLE_ROW);
        $this->get(route('garmin.import.review'))
            ->assertSee('<div><dt>Clashes to decide</dt><dd>0</dd></div>', escape: false)
            ->assertSee('<div><dt>New runs</dt><dd>1</dd></div>', escape: false);
    }

    public function test_viewing_the_dashboard_any_window_or_a_day_detail_writes_nothing(): void
    {
        $owner = $this->signInOwner();
        Activity::factory()->for($owner)->create(['start_date_local' => '2026-09-03 07:00:00', 'activity_date' => '2026-09-03']);
        Activity::factory()->for($owner)->create(['start_date_local' => '2026-06-02 07:00:00', 'activity_date' => '2026-06-02']);

        $before = $this->snapshot();

        foreach (['/', '/?to=2026-08-09', '/?to=2026-06-07', '/?to=2020-01-05'] as $url) {
            $this->get($url)->assertOk();
        }

        $this->assertSame($before, $this->snapshot());
    }

    private function assertNoStrava(TestResponse $response): void
    {
        $html = (string) $response->getContent();

        $this->assertStringNotContainsStringIgnoringCase('strava', $html);
        $this->assertStringNotContainsString('role="alert"', $html);
    }

    /**
     * @return array{users: int, activities: int, updated: mixed, stamp: mixed}
     */
    private function snapshot(): array
    {
        return [
            'users' => DB::table('users')->count(),
            'activities' => DB::table('activities')->count(),
            'updated' => DB::table('activities')->max('updated_at'),
            'stamp' => DB::table('users')->max('updated_at'),
        ];
    }

    private function upload(string $row): void
    {
        $file = UploadedFile::fake()->createWithContent('activities.csv', self::IT_HEADER."\n{$row}\n");

        $this->post(route('garmin.import.store'), ['csv' => $file])->assertRedirect(route('garmin.import.review'));
    }

    private function confirm(TestResponse $review): void
    {
        $html = (string) $review->getContent();

        preg_match('/name="token" value="([^"]+)"/', $html, $token);
        preg_match('/name="fingerprint" value="([^"]+)"/', $html, $fingerprint);
        preg_match_all('/name="resolutions\[(\d+)\]" value="skip"/', $html, $indexes);

        $this->post(route('garmin.import.confirm'), [
            'token' => $token[1] ?? '',
            'fingerprint' => $fingerprint[1] ?? '',
            'resolutions' => array_fill_keys(array_map('intval', $indexes[1]), 'skip'),
        ])->assertRedirect(route('garmin.import.summary'));
    }
}
