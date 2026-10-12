<?php

namespace Tests\Unit\Support\Garmin;

use App\Models\Activity;
use App\Models\User;
use App\Support\Garmin\ResolveGarminImportTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Whose activities an import plans against: the signed-in owner, else the
 * displayed athlete, else nobody yet.
 */
class ResolveGarminImportTargetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_the_signed_in_owner_is_the_target_over_the_displayed_athlete(): void
    {
        $displayed = $this->allowlistedAthlete();
        Activity::factory()->for($displayed)->create();
        $owner = User::factory()->stravaConnected()->create();
        $this->allowlistAthletes($displayed, $owner);

        $target = $this->resolve($owner);

        $this->assertSame($owner->id, $target?->id);
    }

    public function test_a_guest_targets_the_displayed_athlete_without_loading_a_token(): void
    {
        $displayed = $this->allowlistedAthlete();

        $target = $this->resolve(null);

        $this->assertSame($displayed->id, $target?->id);
        $this->assertSame(['id', 'strava_athlete_id'], array_keys($target->getAttributes()));
    }

    public function test_a_guest_targets_a_csv_only_athlete_that_has_runs(): void
    {
        $csvOnly = User::factory()->csvOnly()->create();
        Activity::factory()->garminImported()->for($csvOnly)->create();
        $this->allowlistAthletes();

        $target = $this->resolve(null);

        $this->assertSame($csvOnly->id, $target?->id);
    }

    public function test_a_guest_has_no_target_when_no_stored_athlete_has_an_activity(): void
    {
        User::factory()->stravaConnected()->create();
        $this->allowlistAthletes();

        $this->assertNull($this->resolve(null));
    }

    private function resolve(?User $signedIn): ?User
    {
        return app(ResolveGarminImportTarget::class)($signedIn);
    }
}
