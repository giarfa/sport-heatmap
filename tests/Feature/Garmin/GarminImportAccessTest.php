<?php

namespace Tests\Feature\Garmin;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The import belongs to the signed-in owner (US-020), and every other guard
 * stays: CSRF, the 5 MB limit, the throttle, and the session binding.
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

    /**
     * @return array<string, array{string, string}>
     */
    public static function signedOutRequests(): array
    {
        return [
            'upload form' => ['get', 'garmin.import.create'],
            'upload' => ['post', 'garmin.import.store'],
            'review' => ['get', 'garmin.import.review'],
            'confirm' => ['post', 'garmin.import.confirm'],
            'cancel' => ['post', 'garmin.import.cancel'],
            'summary' => ['get', 'garmin.import.summary'],
        ];
    }

    #[DataProvider('signedOutRequests')]
    public function test_a_signed_out_request_goes_to_the_sign_in_page_and_writes_nothing(string $method, string $name): void
    {
        $this->{$method}(route($name), $method === 'post' ? ['csv' => $this->fixture()] : [])
            ->assertRedirect(route('login'));

        $this->assertSame(0, Activity::count());
        $this->assertSame(0, User::count());
        $this->assertNull(session('garmin_import.pending'));
    }

    public function test_the_owner_reaches_the_upload_form(): void
    {
        $this->signInOwner();

        $this->get(route('garmin.import.create'))
            ->assertOk()
            ->assertSee('<label for="csv" class="field-label">Activities export (.csv)</label>', escape: false)
            ->assertSee('name="_token"', escape: false);
    }

    public function test_a_review_with_no_pending_upload_goes_to_the_upload_form(): void
    {
        $this->signInOwner();

        $this->get(route('garmin.import.review'))->assertRedirect(route('garmin.import.create'));
    }

    public function test_a_summary_with_no_import_behind_it_goes_to_the_map(): void
    {
        $this->signInOwner();

        $this->get(route('garmin.import.summary'))->assertRedirect(route('dashboard'));
    }

    public function test_an_upload_is_parked_in_the_owners_session_for_review(): void
    {
        $this->signInOwner();

        $response = $this->post(route('garmin.import.store'), ['csv' => $this->fixture()]);

        $response->assertRedirect(route('garmin.import.review'));
        $this->assertNotNull(session('garmin_import.pending'));
        $this->assertSame(0, Activity::count());
    }

    public function test_a_cancel_clears_the_pending_upload(): void
    {
        $this->signInOwner();
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
            'review' => ['garmin.import.review', true],
            'confirm' => ['garmin.import.confirm', true],
            'cancel' => ['garmin.import.cancel', false],
            'summary' => ['garmin.import.summary', false],
        ];
    }

    /**
     * CSRF is skipped while tests run, so the stack is asserted instead: the
     * web group carries it, and the owner gate sits in front of every route.
     */
    #[DataProvider('importRoutes')]
    public function test_every_import_route_keeps_csrf_and_sits_behind_the_owner_gate(string $name, bool $throttled): void
    {
        $middleware = Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

        $this->assertContains('web', $middleware);
        $this->assertContains('auth', $middleware);
        $this->assertContains('password.set', $middleware);
        $this->assertSame($throttled, in_array('throttle:garmin-import', $middleware, true));
    }

    public function test_an_eleventh_upload_in_a_minute_is_throttled(): void
    {
        $this->signInOwner();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->post(route('garmin.import.store'), ['csv' => $this->fixture()])->assertRedirect();
        }

        $this->post(route('garmin.import.store'), ['csv' => $this->fixture()])->assertTooManyRequests();
    }

    public function test_an_eleventh_review_in_a_minute_is_throttled(): void
    {
        $this->signInOwner();

        // Upload and review share one limiter, so the upload takes one of the ten.
        $this->post(route('garmin.import.store'), ['csv' => $this->fixture()])->assertRedirect();

        for ($attempt = 1; $attempt <= 9; $attempt++) {
            $this->get(route('garmin.import.review'))->assertOk();
        }

        $this->get(route('garmin.import.review'))->assertTooManyRequests();
    }

    public function test_an_upload_larger_than_5_mb_is_rejected_with_nothing_written(): void
    {
        $this->signInOwner();

        $response = $this->post(route('garmin.import.store'), [
            'csv' => UploadedFile::fake()->create('activities.csv', 5_121, 'text/csv'),
        ]);

        $response->assertSessionHasErrors(['csv' => 'The file is larger than 5 MB.']);
        $this->assertNull(session('garmin_import.pending'));
        $this->assertSame(0, Activity::count());
    }

    private function fixture(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('activities.csv', (string) file_get_contents(base_path('tests/Fixtures/Garmin/activities-it.csv')));
    }
}
