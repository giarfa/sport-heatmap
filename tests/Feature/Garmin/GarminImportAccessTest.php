<?php

namespace Tests\Feature\Garmin;

use App\Models\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The import is owner-only: a guest can neither see it nor write through it.
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
     * @return array<string, array{string}>
     */
    public static function getRoutes(): array
    {
        return [
            'upload form' => ['garmin.import.create'],
            'review' => ['garmin.import.review'],
            'summary' => ['garmin.import.summary'],
        ];
    }

    #[DataProvider('getRoutes')]
    public function test_a_guest_get_is_redirected_home(string $route): void
    {
        $this->allowlistedAthlete();

        $this->get(route($route))->assertRedirect(route('dashboard'));
        $this->assertGuest();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function postRoutes(): array
    {
        return [
            'upload' => ['garmin.import.store'],
            'confirm' => ['garmin.import.confirm'],
            'cancel' => ['garmin.import.cancel'],
        ];
    }

    #[DataProvider('postRoutes')]
    public function test_a_guest_post_is_refused_with_nothing_written(string $route): void
    {
        $owner = $this->allowlistedAthlete();
        Activity::factory()->for($owner)->create();

        $this->post(route($route), [
            'csv' => UploadedFile::fake()->createWithContent('activities.csv', file_get_contents(base_path('tests/Fixtures/Garmin/activities-it.csv'))),
            'token' => 'forged',
            'fingerprint' => 'forged',
            'resolutions' => [0 => 'overwrite'],
        ])->assertRedirect(route('dashboard'));

        $this->assertSame(1, Activity::count());
        $this->assertNull(session('garmin_import.pending'));
        $this->assertGuest();
    }

    public function test_a_signed_in_athlete_removed_from_the_allowlist_is_refused(): void
    {
        $owner = $this->allowlistedAthlete();
        $this->allowlistAthletes('99999999');

        $this->actingAs($owner)
            ->post(route('garmin.import.store'), [
                'csv' => UploadedFile::fake()->createWithContent('activities.csv', file_get_contents(base_path('tests/Fixtures/Garmin/activities-it.csv'))),
            ])
            ->assertForbidden();

        $this->assertSame(0, Activity::count());
        $this->assertNull(session('garmin_import.pending'));
    }

    public function test_the_signed_in_owner_reaches_the_upload_form(): void
    {
        $this->actingAs($this->allowlistedAthlete())
            ->get(route('garmin.import.create'))
            ->assertOk()
            ->assertSee('<label for="csv" class="field-label">Activities export (.csv)</label>', escape: false)
            ->assertSee('name="_token"', escape: false);
    }
}
