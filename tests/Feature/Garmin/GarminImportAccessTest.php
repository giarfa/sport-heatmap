<?php

namespace Tests\Feature\Garmin;

use App\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The import is open to signed-out visitors (US-011), and every guard other
 * than sign-in stays: CSRF, the 5 MB limit, the throttle, and the allowlist
 * for a signed-in owner.
 */
class GarminImportAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow('2026-10-11 12:00:00');
    }

    public function test_a_guest_reaches_the_upload_form(): void
    {
        $this->get(route('garmin.import.create'))
            ->assertOk()
            ->assertSee('<label for="csv" class="field-label">Activities export (.csv)</label>', escape: false)
            ->assertSee('name="_token"', escape: false);
    }

    public function test_a_guest_review_with_no_pending_upload_goes_to_the_upload_form(): void
    {
        $this->get(route('garmin.import.review'))->assertRedirect(route('garmin.import.create'));
    }

    public function test_a_guest_summary_with_no_import_behind_it_goes_to_the_map(): void
    {
        $this->get(route('garmin.import.summary'))->assertRedirect(route('dashboard'));
    }

    public function test_a_guest_upload_is_parked_in_the_guest_session_for_review(): void
    {
        $response = $this->post(route('garmin.import.store'), ['csv' => $this->fixture()]);

        $response->assertRedirect(route('garmin.import.review'));
        $this->assertNotNull(session('garmin_import.pending'));
        $this->assertSame(0, Activity::count());
        $this->assertGuest();
    }

    public function test_a_guest_cancel_clears_the_pending_upload(): void
    {
        $this->post(route('garmin.import.store'), ['csv' => $this->fixture()]);

        $this->post(route('garmin.import.cancel'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'Import cancelled. Nothing was written.');

        $this->assertNull(session('garmin_import.pending'));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function importRoutes(): array
    {
        return [
            'upload form' => ['garmin.import.create', false],
            'upload' => ['garmin.import.store', true],
            'review' => ['garmin.import.review', false],
            'confirm' => ['garmin.import.confirm', true],
            'cancel' => ['garmin.import.cancel', false],
            'summary' => ['garmin.import.summary', false],
        ];
    }

    /**
     * CSRF is skipped while tests run, so the stack is asserted instead: the
     * web group carries it, and no route may require a sign-in again.
     */
    #[DataProvider('importRoutes')]
    public function test_every_import_route_keeps_csrf_and_the_allowlist_guard_without_requiring_sign_in(string $name, bool $throttled): void
    {
        $middleware = Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

        $this->assertContains('web', $middleware);
        $this->assertContains('strava.fresh', $middleware);
        $this->assertNotContains('auth', $middleware);
        $this->assertSame($throttled, in_array('throttle:garmin-import', $middleware, true));
    }

    public function test_an_eleventh_guest_upload_in_a_minute_is_throttled(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->post(route('garmin.import.store'), ['csv' => $this->fixture()])->assertRedirect();
        }

        $this->post(route('garmin.import.store'), ['csv' => $this->fixture()])->assertTooManyRequests();
    }

    public function test_a_guest_upload_larger_than_5_mb_is_rejected_with_nothing_written(): void
    {
        $response = $this->post(route('garmin.import.store'), [
            'csv' => UploadedFile::fake()->create('activities.csv', 5_121, 'text/csv'),
        ]);

        $response->assertSessionHasErrors(['csv' => 'The file is larger than 5 MB.']);
        $this->assertNull(session('garmin_import.pending'));
        $this->assertSame(0, Activity::count());
    }

    public function test_a_signed_in_athlete_removed_from_the_allowlist_is_refused(): void
    {
        $owner = $this->allowlistedAthlete();
        $this->allowlistAthletes('99999999');

        $this->actingAs($owner)
            ->post(route('garmin.import.store'), ['csv' => $this->fixture()])
            ->assertForbidden();

        $this->assertSame(0, Activity::count());
        $this->assertNull(session('garmin_import.pending'));
    }

    public function test_the_signed_in_owner_reaches_the_upload_form(): void
    {
        $this->actingAs($this->allowlistedAthlete())
            ->get(route('garmin.import.create'))
            ->assertOk()
            ->assertSee('<label for="csv" class="field-label">Activities export (.csv)</label>', escape: false);
    }

    private function fixture(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('activities.csv', (string) file_get_contents(base_path('tests/Fixtures/Garmin/activities-it.csv')));
    }
}
