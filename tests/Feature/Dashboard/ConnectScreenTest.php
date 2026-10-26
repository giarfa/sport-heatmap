<?php

namespace Tests\Feature\Dashboard;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The empty screen an owner with no run and no Strava connection sees, plus the layout invariants that US-004, US-005 and
 * US-006 will build on and could quietly break.
 */
class ConnectScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_an_owner_with_nothing_to_show_gets_the_connect_screen_rather_than_an_error(): void
    {
        $this->signInOwner();

        $response = $this->get('/');

        $response->assertOk();
        $response->assertViewIs('strava.connect');
        $response->assertSee('Connect with Strava');
        $response->assertSee(route('strava.redirect'), escape: false);
    }

    public function test_the_connect_screen_also_offers_the_garmin_import(): void
    {
        $this->signInOwner();

        $response = $this->get('/');

        $response->assertOk();
        $response->assertViewIs('strava.connect');
        $response->assertSee('Connect with Strava');
        $response->assertSee('Connect Strava or import a Garmin Connect CSV to build your 16-week heatmap.');
        $response->assertSee('<a href="'.route('garmin.import.create').'" class="btn btn--secondary mt-3 w-full">Import Garmin CSV</a>', escape: false);
    }

    public function test_the_import_form_keeps_noindex_and_the_strava_attribution(): void
    {
        $this->signInOwner();

        $response = $this->get(route('garmin.import.create'));

        $response->assertOk();
        $response->assertSee('<meta name="robots" content="noindex, nofollow">', escape: false);
        $response->assertSee('Powered by Strava');
    }

    public function test_a_connected_athlete_gets_the_dashboard(): void
    {
        $response = $this->actingAs($this->allowlistedAthlete())->get('/');

        $response->assertOk();
        $response->assertViewIs('dashboard');
    }

    public function test_the_connect_screen_states_the_scope_and_the_read_only_promise(): void
    {
        $this->signInOwner();

        $response = $this->get('/');

        $response->assertSee('activity:read_all');
        $response->assertSee('nothing is ever posted on your behalf', escape: false);
    }

    public function test_every_screen_is_marked_noindex(): void
    {
        foreach ($this->bothScreens() as $label => $response) {
            $this->assertStringContainsString(
                '<meta name="robots" content="noindex, nofollow">',
                $response->getContent() ?: '',
                "The {$label} screen must not be indexable.",
            );
        }
    }

    public function test_every_screen_carries_a_skip_link_and_exactly_one_heading(): void
    {
        foreach ($this->bothScreens() as $label => $response) {
            $html = $response->getContent() ?: '';

            $this->assertStringContainsString('href="#main"', $html, "The {$label} screen needs a skip link.");
            $this->assertStringContainsString('id="main"', $html, "The {$label} screen needs a main landmark.");
            $this->assertSame(
                1,
                substr_count($html, '<h1'),
                "The {$label} screen must have exactly one <h1>.",
            );
        }
    }

    public function test_the_rejection_screen_names_the_athlete_it_turned_away(): void
    {
        $response = $this->view('strava.rejected', ['reason' => 'allowlist', 'athleteId' => '9999999']);

        $response->assertSee('This instance is private');
        $response->assertSee('9999999', escape: false);
    }

    public function test_the_rejection_screen_explains_a_missing_scope(): void
    {
        $response = $this->view('strava.rejected', [
            'reason' => 'scope',
            'requiredScope' => 'activity:read_all',
        ]);

        $response->assertSee('Read access is required');
        $response->assertSee('activity:read_all');
    }

    public function test_the_rejection_screen_explains_a_refused_write_scope(): void
    {
        $response = $this->view('strava.rejected', [
            'reason' => 'write_scope',
            'requiredScope' => 'activity:read_all',
        ]);

        $response->assertSee('Only read access is accepted');
        $response->assertSee('activity:read_all');
    }

    public function test_the_connect_screen_renders_the_cancellation_notice(): void
    {
        $this->signInOwner();

        $response = $this->withSession(['status' => 'Strava authorization was cancelled. Nothing was stored.'])
            ->get('/');

        $response->assertOk();
        $response->assertSee('Strava authorization was cancelled. Nothing was stored.');
    }

    public function test_the_map_renders_the_cancellation_notice(): void
    {
        $this->allowlistedAthlete();

        $response = $this->withSession(['status' => 'Strava authorization was cancelled. Nothing was stored.'])
            ->get('/');

        $response->assertViewIs('dashboard');
        $response->assertSee('Strava authorization was cancelled. Nothing was stored.');
    }

    /**
     * @return array<string, TestResponse>
     */
    private function bothScreens(): array
    {
        $this->signInOwner();
        $connect = $this->get('/');

        return [
            'connect' => $connect,
            'dashboard' => $this->actingAs($this->allowlistedAthlete())->get('/'),
        ];
    }
}
