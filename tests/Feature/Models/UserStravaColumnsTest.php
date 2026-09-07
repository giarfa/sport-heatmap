<?php

namespace Tests\Feature\Models;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserStravaColumnsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A mis-declared cast still saves — it just stores plaintext. Reading the raw
     * row is the only assertion that actually proves the tokens are encrypted.
     */
    public function test_strava_tokens_are_encrypted_at_rest(): void
    {
        $accessToken = 'plain-access-token-value';
        $refreshToken = 'plain-refresh-token-value';

        $user = User::factory()->create([
            'strava_access_token' => $accessToken,
            'strava_refresh_token' => $refreshToken,
        ]);

        $user->refresh();

        $this->assertSame($accessToken, $user->strava_access_token);
        $this->assertSame($refreshToken, $user->strava_refresh_token);

        $raw = DB::table('users')->where('id', $user->id)->first();

        $this->assertNotEmpty($raw->strava_access_token);
        $this->assertNotEmpty($raw->strava_refresh_token);
        $this->assertNotSame($accessToken, $raw->strava_access_token);
        $this->assertNotSame($refreshToken, $raw->strava_refresh_token);
    }

    public function test_strava_tokens_are_hidden_from_serialization(): void
    {
        $user = User::factory()->stravaConnected()->create();

        $serialized = $user->toArray();

        $this->assertArrayNotHasKey('strava_access_token', $serialized);
        $this->assertArrayNotHasKey('strava_refresh_token', $serialized);
        $this->assertArrayNotHasKey('password', $serialized);
        $this->assertArrayHasKey('strava_athlete_id', $serialized);
    }

    public function test_strava_athlete_id_uniqueness_is_enforced(): void
    {
        User::factory()->create(['strava_athlete_id' => '12345678']);

        $this->expectException(QueryException::class);

        User::factory()->create(['strava_athlete_id' => '12345678']);
    }

    public function test_a_user_can_be_created_without_a_password(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->fresh()->password);
    }

    public function test_the_default_factory_state_leaves_the_strava_columns_empty(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->strava_athlete_id);
        $this->assertNull($user->strava_access_token);
        $this->assertNull($user->strava_refresh_token);
        $this->assertNull($user->strava_token_expires_at);
    }

    public function test_the_strava_connected_state_produces_a_live_token(): void
    {
        $user = User::factory()->stravaConnected()->create();

        $this->assertNotNull($user->strava_athlete_id);
        $this->assertNotNull($user->strava_access_token);
        $this->assertNotNull($user->strava_refresh_token);
        $this->assertTrue($user->strava_token_expires_at->isFuture());
    }

    public function test_the_strava_token_expired_state_produces_a_past_expiry(): void
    {
        $user = User::factory()->stravaTokenExpired()->create();

        $this->assertNotNull($user->strava_refresh_token);
        $this->assertTrue($user->strava_token_expires_at->isPast());
    }

    public function test_the_unverified_state_leaves_the_email_unverified(): void
    {
        $this->assertNull(User::factory()->unverified()->create()->email_verified_at);
    }
}
