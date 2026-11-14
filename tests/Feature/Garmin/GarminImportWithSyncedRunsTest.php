<?php

namespace Tests\Feature\Garmin;

use App\Enums\ActivitySource;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The CSV import with runs the Garmin sync wrote already stored: a CSV row that matches
 * one goes through the same review, and Overwrite keeps the run's Garmin id.
 */
class GarminImportWithSyncedRunsTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'Tipo di attività,Data,Preferita,Titolo,Distanza,Tempo,Tempo in movimento';

    private const ROW = 'Corsa,2026-10-10 09:33:27,false,"Paese Corsa","8.29","00:54:24","00:54:23"';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow('2026-10-11 12:00:00');

        $this->owner = $this->signInOwner();
    }

    public function test_a_csv_row_within_two_minutes_of_a_synced_run_is_a_conflict_in_the_review(): void
    {
        $this->syncedRun();

        $this->upload();

        $this->review()->assertSee('<div><dt>Clashes to decide</dt><dd>1</dd></div>', escape: false)
            ->assertSee('<div><dt>New runs</dt><dd>0</dd></div>', escape: false);
    }

    public function test_skip_leaves_the_synced_run_untouched(): void
    {
        $run = $this->syncedRun();
        $before = $run->fresh()->getAttributes();

        $this->upload();
        $this->confirm($this->review(), [0 => 'skip']);

        $this->assertSame($before, $run->fresh()->getAttributes());
    }

    public function test_overwrite_writes_the_csv_values_marks_the_run_csv_and_keeps_its_garmin_id(): void
    {
        $run = $this->syncedRun();

        $this->upload();
        $this->confirm($this->review(), [0 => 'overwrite'])->assertRedirect(route('garmin.import.summary'));

        $run->refresh();
        $this->assertSame(1, Activity::count());
        $this->assertSame('Paese Corsa', $run->name);
        $this->assertSame(8290, $run->distance_meters);
        $this->assertSame(3263, $run->moving_time_seconds);
        $this->assertSame('2026-10-10 09:33:27', $run->start_date_local->format('Y-m-d H:i:s'));
        $this->assertSame(ActivitySource::GarminCsv, $run->source);
        $this->assertSame(12_345_678_901, $run->garmin_activity_id, 'The Garmin id is the run\'s identity and a CSV row has none.');
    }

    public function test_a_new_csv_run_is_stored_as_a_csv_import_with_no_garmin_id(): void
    {
        $this->upload();
        $this->confirm($this->review());

        $run = Activity::sole();

        $this->assertSame(ActivitySource::GarminCsv, $run->source);
        $this->assertNull($run->garmin_activity_id);
    }

    public function test_re_importing_the_same_file_after_an_overwrite_changes_nothing(): void
    {
        $run = $this->syncedRun();
        $this->upload();
        $this->confirm($this->review(), [0 => 'overwrite']);
        $before = $run->fresh()->getAttributes();

        $this->upload();
        $this->review()->assertSee('<div><dt>Clashes to decide</dt><dd>0</dd></div>', escape: false);
        $this->confirm($this->review());

        $this->assertSame($before, $run->fresh()->getAttributes());
        $this->assertSame(1, Activity::count());
    }

    public function test_an_unrelated_synced_run_on_another_day_is_not_a_conflict(): void
    {
        $this->syncedRun('2026-10-09 09:33:00');

        $this->upload();

        $this->review()->assertSee('<div><dt>Clashes to decide</dt><dd>0</dd></div>', escape: false)
            ->assertSee('<div><dt>New runs</dt><dd>1</dd></div>', escape: false);
    }

    private function syncedRun(string $start = '2026-10-10 09:34:00'): Activity
    {
        return Activity::factory()->for($this->owner)->garminSynced()->create([
            'name' => 'Verona Corsa',
            'distance_meters' => 8_100,
            'moving_time_seconds' => 3_200,
            'start_date_local' => $start,
            'activity_date' => substr($start, 0, 10),
            'garmin_activity_id' => 12_345_678_901,
        ]);
    }

    private function upload(): TestResponse
    {
        $file = UploadedFile::fake()->createWithContent('activities.csv', implode("\n", [self::HEADER, self::ROW])."\n");

        return $this->actingAs($this->owner)->post(route('garmin.import.store'), ['csv' => $file]);
    }

    private function review(): TestResponse
    {
        return $this->actingAs($this->owner)->get(route('garmin.import.review'))->assertOk();
    }

    /**
     * Post the review's own hidden token and fingerprint back, Skip for every conflict
     * unless told otherwise.
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
}
