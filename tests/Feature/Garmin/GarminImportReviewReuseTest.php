<?php

namespace Tests\Feature\Garmin;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Reloading the review reuses the plan it already computed (US-018) while the
 * stored runs on the upload's dates are unchanged, and plans again as soon as
 * one of them, the target, or the upload changes.
 */
class GarminImportReviewReuseTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'Tipo di attività,Data,Preferita,Titolo,Distanza,Tempo,Tempo in movimento';

    private const FIRST_ROW = 'Corsa,2026-10-10 09:33:27,false,"Paese Corsa","8.29","00:54:24","00:54:23"';

    private const OTHER_DAY_ROW = 'Corsa,2026-10-04 07:10:00,false,"Giro lungo","16.02","01:30:00","01:29:40"';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow('2026-10-11 12:00:00');

        $this->owner = $this->signInOwner();
    }

    public function test_a_reload_serves_the_remembered_plan_instead_of_planning_again(): void
    {
        $this->storedRun('2026-10-10 09:31:28');
        $this->upload(self::FIRST_ROW);

        $this->assertTally($this->review(), new: 0, clashes: 1);
        $this->assertIsString(session('garmin_import.pending.plan.stamp'));

        // A plan computed from the database now would find the clash; only the remembered one says none.
        $reload = $this->withSession([
            'garmin_import.pending.plan.new' => [0],
            'garmin_import.pending.plan.conflicts' => [],
        ])->review();

        $this->assertTally($reload, new: 1, clashes: 0);
    }

    public function test_a_stored_run_added_on_an_upload_date_shows_its_clash_on_reload(): void
    {
        $this->upload(self::FIRST_ROW);
        $this->assertTally($this->review(), new: 1, clashes: 0);

        $stored = $this->storedRun('2026-10-10 09:31:28');

        $review = $this->review();

        $this->assertTally($review, new: 0, clashes: 1);
        $review->assertSee($stored->name);
    }

    public function test_a_stored_run_moved_out_of_tolerance_drops_its_clash_on_reload(): void
    {
        $stored = $this->storedRun('2026-10-10 09:31:28');
        $this->upload(self::FIRST_ROW);
        $this->assertTally($this->review(), new: 0, clashes: 1);

        $stored->update(['start_date_local' => '2026-10-10 09:50:00']);

        $this->assertTally($this->review(), new: 1, clashes: 0);
    }

    public function test_a_stored_run_removed_from_an_upload_date_drops_its_clash_on_reload(): void
    {
        $stored = $this->storedRun('2026-10-10 09:31:28');
        $this->upload(self::FIRST_ROW);
        $this->assertTally($this->review(), new: 0, clashes: 1);

        $stored->delete();

        $this->assertTally($this->review(), new: 1, clashes: 0);
    }

    public function test_a_stored_run_changed_to_match_the_row_exactly_moves_it_to_already_imported_on_reload(): void
    {
        $stored = $this->storedRun('2026-10-10 09:31:28');
        $this->upload(self::FIRST_ROW);
        $this->assertTally($this->review(), new: 0, clashes: 1);

        $stored->update([
            'start_date_local' => '2026-10-10 09:33:27',
            'distance_meters' => 8290,
            'moving_time_seconds' => 3263,
        ]);

        $this->review()->assertSee('<div><dt>Already imported</dt><dd>1</dd></div>', escape: false);
    }

    public function test_a_new_upload_is_planned_afresh_not_from_the_previous_uploads_plan(): void
    {
        $this->storedRun('2026-10-10 09:31:28');
        $this->upload(self::FIRST_ROW);
        $this->assertTally($this->review(), new: 0, clashes: 1);

        $this->upload(self::OTHER_DAY_ROW);

        $this->assertTally($this->review(), new: 1, clashes: 0);
    }

    public function test_the_confirm_accepts_the_fingerprint_of_a_reloaded_review(): void
    {
        $this->storedRun('2026-10-10 09:31:28');
        $this->upload(self::FIRST_ROW);
        $this->review();
        $reload = $this->review();

        $this->confirm($reload)->assertRedirect(route('garmin.import.summary'));

        $this->assertSame(1, Activity::count());
    }

    private function upload(string ...$rows): TestResponse
    {
        $file = UploadedFile::fake()->createWithContent('activities.csv', implode("\n", [self::HEADER, ...$rows])."\n");

        return $this->actingAs($this->owner)->post(route('garmin.import.store'), ['csv' => $file]);
    }

    private function review(): TestResponse
    {
        return $this->actingAs($this->owner)->get(route('garmin.import.review'))->assertOk();
    }

    /**
     * Post the review's own token and fingerprint back, the way the form does,
     * with Skip for every conflict.
     */
    private function confirm(TestResponse $review): TestResponse
    {
        $html = (string) $review->getContent();

        preg_match('/name="token" value="([^"]+)"/', $html, $token);
        preg_match('/name="fingerprint" value="([^"]+)"/', $html, $fingerprint);
        preg_match_all('/name="resolutions\[(\d+)\]" value="skip"/', $html, $indexes);

        return $this->actingAs($this->owner)->post(route('garmin.import.confirm'), [
            'token' => $token[1] ?? '',
            'fingerprint' => $fingerprint[1] ?? '',
            'resolutions' => array_fill_keys(array_map('intval', $indexes[1]), 'skip'),
        ]);
    }

    private function storedRun(string $start): Activity
    {
        return Activity::factory()->for($this->owner)->create([
            'start_date_local' => $start,
            'activity_date' => substr($start, 0, 10),
        ]);
    }

    private function assertTally(TestResponse $review, int $new, int $clashes): void
    {
        $review->assertSee("<div><dt>New runs</dt><dd>{$new}</dd></div>", escape: false);
        $review->assertSee("<div><dt>Clashes to decide</dt><dd>{$clashes}</dd></div>", escape: false);
    }
}
