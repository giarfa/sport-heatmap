<?php

namespace Tests\Feature\Dashboard;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What an owner with no run sees: one offer, the Garmin CSV import.
 */
class EmptyStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_an_owner_with_no_run_gets_the_garmin_only_empty_state_rather_than_an_error(): void
    {
        $this->signInOwner();

        $response = $this->get('/');

        $response->assertOk();
        $response->assertViewIs('dashboard-empty');
        $response->assertSee('Import a Garmin Connect CSV export to build your 16-week heatmap.');
        $response->assertSee('href="'.route('garmin.import.create').'" class="btn btn--primary mt-4 w-full">Import Garmin CSV</a>', escape: false);
        $response->assertSee('No runs imported yet');
        $response->assertDontSee('Connect with Strava');
        $response->assertDontSee('role="alert"', escape: false);
    }

    public function test_a_signed_out_visitor_is_sent_to_the_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_the_empty_state_is_marked_noindex_with_a_skip_link_and_one_heading(): void
    {
        $this->signInOwner();

        $html = $this->get('/')->getContent() ?: '';

        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        $this->assertStringContainsString('href="#main"', $html);
        $this->assertStringContainsString('id="main"', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_the_empty_state_has_no_trend_card_and_writes_nothing(): void
    {
        $this->signInOwner();
        $before = [DB::table('users')->count(), DB::table('activities')->count()];

        $html = $this->get('/')->getContent() ?: '';

        $this->assertStringNotContainsString('trend-heading', $html);
        $this->assertSame($before, [DB::table('users')->count(), DB::table('activities')->count()]);
    }

    public function test_the_import_form_stays_noindex(): void
    {
        $this->signInOwner();

        $this->get(route('garmin.import.create'))
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', escape: false);
    }
}
