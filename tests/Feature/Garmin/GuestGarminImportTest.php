<?php

namespace Tests\Feature\Garmin;

use App\Enums\ActivitySource;
use App\Models\Activity;
use App\Models\User;
use App\Support\Garmin\GarminImportFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The signed-out upload → review → confirm → summary flow (US-011): into the
 * displayed athlete, or into a new CSV-only athlete when none is displayed —
 * never reaching Strava, never touching a token, and one session per import.
 */
class GuestGarminImportTest extends TestCase
{
    use RefreshDatabase;

    private const IT_HEADER = 'Tipo di attività,Data,Preferita,Titolo,Distanza,Tempo,Tempo in movimento';

    private const SAMPLE_FIRST_ROW = 'Corsa,2026-10-10 09:33:27,false,"Paese Corsa","8.29","00:54:24","00:54:23"';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['www.strava.com/*' => Http::response(['message' => 'unexpected'], 500)]);
        Carbon::setTestNow('2026-10-11 12:00:00');
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function fixtures(): array
    {
        return [
            'italian headers' => ['activities-it.csv', 37],
            'english headers' => ['activities-en.csv', 7],
        ];
    }

    #[DataProvider('fixtures')]
    public function test_a_guest_imports_into_the_displayed_athlete(string $fixture, int $runs): void
    {
        $displayed = $this->allowlistedAthlete();

        $this->upload($this->fixture($fixture))->assertRedirect(route('garmin.import.review'));
        $this->confirm($this->review())->assertRedirect(route('garmin.import.summary'));

        $this->get(route('garmin.import.summary'))->assertOk()->assertSee("{$runs} runs added, 0 overwritten.");
        $this->assertSame($runs, $displayed->activities()->where('source', ActivitySource::GarminCsv)->count());
        $this->assertSame(1, User::count());
        $this->assertGuest();
    }

    #[DataProvider('fixtures')]
    public function test_a_guest_first_import_creates_the_csv_only_athlete_the_home_page_then_displays(string $fixture, int $runs): void
    {
        Storage::fake('local');

        $this->upload($this->fixture($fixture));
        $this->confirm($this->review())->assertRedirect(route('garmin.import.summary'));

        $athlete = User::sole();
        $this->assertTrue($athlete->isCsvOnly());
        $this->assertNull($athlete->strava_access_token);
        $this->assertNull($athlete->strava_refresh_token);
        $this->assertSame($runs, $athlete->activities()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->get(route('dashboard'))->assertOk()->assertViewIs('dashboard');
    }

    public function test_a_guest_resolves_a_clash_with_overwrite_on_the_displayed_athlete(): void
    {
        $displayed = $this->allowlistedAthlete();
        $stored = Activity::factory()->for($displayed)->create([
            'start_date_local' => '2026-10-10 09:34:10',
            'activity_date' => '2026-10-10',
            'strava_activity_id' => 4_242,
            'distance_meters' => 8_150,
        ]);

        $this->upload($this->csv([self::SAMPLE_FIRST_ROW]));
        $review = $this->review();
        $review->assertSee('<div><dt>Clashes to decide</dt><dd>1</dd></div>', escape: false);
        $this->confirm($review, [0 => 'overwrite'])->assertRedirect(route('garmin.import.summary'));

        $this->assertSame(1, Activity::count());
        $this->assertSame(ActivitySource::GarminCsv, $stored->fresh()->source);
        $this->assertSame(8_290, $stored->fresh()->distance_meters);
    }

    public function test_a_guest_review_masks_the_stored_side_of_a_clash(): void
    {
        $stored = $this->storedClashingRun($this->allowlistedAthlete());

        $this->upload($this->csv([self::SAMPLE_FIRST_ROW]));
        $review = $this->review();

        $review->assertSee('Clashes with a stored run');
        $review->assertSee(GarminImportFormat::start($stored->start_date_local));
        $review->assertDontSee($stored->name);
        $review->assertDontSee(GarminImportFormat::distance(12_340));
        $review->assertDontSee(GarminImportFormat::duration(3_725));
        $review->assertDontSee($stored->stravaUrl(), escape: false);
        $review->assertDontSee('View on Strava');
        $review->assertSee('value="overwrite"', escape: false);
        $this->confirm($review)->assertRedirect(route('garmin.import.summary'));
    }

    public function test_a_guest_review_hides_the_stored_runs_metrics_too(): void
    {
        $stored = $this->storedClashingRun($this->allowlistedAthlete());
        $stored->update([
            'average_pace_seconds_per_km' => 331,
            'total_ascent_meters' => 62,
            'average_heart_rate' => 150,
        ]);

        $this->upload($this->csv([self::SAMPLE_FIRST_ROW]));
        $review = $this->review();

        $review->assertDontSee('5:31 /km');
        $review->assertDontSee('62 m');
        $review->assertDontSee('150 bpm');
        $this->assertSame(1, substr_count((string) $review->getContent(), 'Avg pace'), 'Only the file side carries the metric rows.');
    }

    public function test_the_signed_in_owner_review_still_shows_every_stored_detail(): void
    {
        $owner = $this->allowlistedAthlete();
        $stored = $this->storedClashingRun($owner);

        $this->actingAs($owner)->post(route('garmin.import.store'), ['csv' => $this->csv([self::SAMPLE_FIRST_ROW])]);
        $review = $this->actingAs($owner)->get(route('garmin.import.review'))->assertOk();

        $review->assertSee($stored->name);
        $review->assertSee(GarminImportFormat::distance(12_340));
        $review->assertSee(GarminImportFormat::duration(3_725));
        $review->assertSee($stored->stravaUrl(), escape: false);
        $review->assertDontSee('Clashes with a stored run');
    }

    public function test_a_guest_re_importing_the_same_file_changes_nothing(): void
    {
        $this->upload($this->fixture('activities-it.csv'));
        $this->confirm($this->review());

        $this->upload($this->fixture('activities-it.csv'));
        $review = $this->review();
        $review->assertSee('<div><dt>New runs</dt><dd>0</dd></div>', escape: false);
        $this->confirm($review);

        $this->assertSame(1, User::count());
        $this->assertSame(37, Activity::count());
    }

    public function test_a_guest_abandoning_the_review_writes_nothing(): void
    {
        $this->upload($this->fixture('activities-it.csv'));
        $this->review();

        $this->assertSame(0, User::count());
        $this->assertSame(0, Activity::count());
    }

    public function test_a_guest_import_never_calls_strava_nor_touches_an_expired_token(): void
    {
        $displayed = $this->allowlistedAthlete(factoryState: 'stravaTokenExpired');
        $before = $displayed->getRawOriginal();

        $this->upload($this->fixture('activities-it.csv'));
        $this->confirm($this->review());

        $after = $displayed->fresh()->getRawOriginal();
        $this->assertSame($before['strava_access_token'], $after['strava_access_token']);
        $this->assertSame($before['strava_refresh_token'], $after['strava_refresh_token']);
        $this->assertSame($before['strava_token_expires_at'], $after['strava_token_expires_at']);
        $this->assertSame(37, $displayed->activities()->count());
        Http::assertNothingSent();
    }

    public function test_two_guest_sessions_never_see_or_confirm_each_other_s_upload(): void
    {
        $this->upload($this->fixture('activities-it.csv'));
        $reviewA = $this->review();
        $sessionA = session()->all();

        $this->flushSession();
        $this->upload($this->fixture('activities-en.csv'));
        $reviewB = $this->review();
        $reviewB->assertSee('<div><dt>New runs</dt><dd>7</dd></div>', escape: false);
        $reviewB->assertDontSee('Paese Corsa');

        $this->confirm($reviewA)->assertRedirect(route('garmin.import.review'));
        $this->assertSame(0, Activity::count());

        $this->flushSession();
        $this->withSession($sessionA);
        $this->confirm($reviewA)->assertRedirect(route('garmin.import.summary'));

        $this->assertSame(37, Activity::count());
        $this->assertSame(0, Activity::where('name', 'Ridge Trail')->count());
    }

    public function test_an_athlete_connecting_mid_review_sends_the_guest_back_to_the_review(): void
    {
        $this->upload($this->fixture('activities-it.csv'));
        $review = $this->review();

        $this->allowlistedAthlete();

        $this->confirm($review)
            ->assertRedirect(route('garmin.import.review'))
            ->assertSessionHas('garmin_import.stale');
        $this->assertSame(0, Activity::count());
        $this->assertSame(1, User::count());
    }

    public function test_a_first_import_confirmed_after_another_goes_back_to_review_leaving_one_csv_only_athlete(): void
    {
        $this->upload($this->fixture('activities-it.csv'));
        $reviewA = $this->review();
        $sessionA = session()->all();

        $this->flushSession();
        $this->upload($this->csv([self::SAMPLE_FIRST_ROW]));
        $reviewB = $this->review();
        $sessionB = session()->all();

        $this->flushSession();
        $this->withSession($sessionA);
        $this->confirm($reviewA)->assertRedirect(route('garmin.import.summary'));

        $this->flushSession();
        $this->withSession($sessionB);
        $this->confirm($reviewB)->assertRedirect(route('garmin.import.review'));

        $this->assertSame(1, User::count());
        $this->assertSame(37, Activity::count());
    }

    public function test_a_confirm_that_waits_out_the_instance_wide_lock_goes_back_to_the_review(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $this->upload($this->fixture('activities-it.csv'));
        $review = $this->review();
        Cache::lock('garmin-import:confirm', 30)->get();

        $response = $this->confirm($review);

        $response->assertRedirect(route('garmin.import.review'));
        $response->assertSessionHas('garmin_import.stale', 'Another import is being saved — confirm again in a moment.');
        $this->assertSame(0, Activity::count());
        $this->assertNotNull(session('garmin_import.pending'));
    }

    private function storedClashingRun(User $athlete): Activity
    {
        return Activity::factory()->for($athlete)->create([
            'name' => 'Private tempo with Giulia',
            'start_date_local' => '2026-10-10 09:34:10',
            'activity_date' => '2026-10-10',
            'strava_activity_id' => 4_242,
            'distance_meters' => 12_340,
            'moving_time_seconds' => 3_725,
        ]);
    }

    private function upload(UploadedFile $file): TestResponse
    {
        return $this->post(route('garmin.import.store'), ['csv' => $file]);
    }

    private function review(): TestResponse
    {
        return $this->get(route('garmin.import.review'))->assertOk();
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

        return $this->post(route('garmin.import.confirm'), [
            'token' => $token[1] ?? '',
            'fingerprint' => $fingerprint[1] ?? '',
            'resolutions' => $resolutions,
        ]);
    }

    private function fixture(string $name): UploadedFile
    {
        return new UploadedFile(base_path("tests/Fixtures/Garmin/{$name}"), $name, 'text/csv', null, true);
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function csv(array $lines): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('activities.csv', implode("\n", [self::IT_HEADER, ...$lines])."\n");
    }
}
