<?php

namespace Tests\Feature\Strava;

use App\Actions\Strava\ConnectStravaAthlete;
use App\Data\StravaTokens;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConnectStravaAthleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tokens_land_on_the_owner_and_no_user_is_created(): void
    {
        $owner = User::factory()->ownerWithPassword()->create(['name' => 'giarfa']);

        $connected = $this->connect($owner, $this->tokens());

        $this->assertSame(1, User::query()->count());
        $this->assertTrue($owner->is($connected));
        $this->assertSame('4821196', $connected->strava_athlete_id);
        $this->assertSame('access-token-value', $connected->strava_access_token);
        $this->assertSame('refresh-token-value', $connected->strava_refresh_token);
        $this->assertTrue(
            CarbonImmutable::createFromTimestampUTC(1_788_000_000)->equalTo($connected->strava_token_expires_at),
        );
    }

    public function test_reconnecting_replaces_the_tokens_on_the_same_owner(): void
    {
        $owner = User::factory()->ownerWithPassword()->create();
        $this->connect($owner, $this->tokens());

        $reconnected = $this->connect($owner, $this->tokens(
            accessToken: 'second-access-token',
            refreshToken: 'second-refresh-token',
        ));

        $this->assertSame(1, User::query()->count());
        $this->assertSame('second-access-token', $reconnected->fresh()->strava_access_token);
        $this->assertSame('second-refresh-token', $reconnected->fresh()->strava_refresh_token);
    }

    /**
     * A mis-declared cast still saves — it just stores plaintext. Reading the raw
     * row is the only assertion that proves the tokens are ciphertext at rest.
     */
    public function test_the_tokens_are_encrypted_at_rest(): void
    {
        $user = $this->connect(User::factory()->ownerWithPassword()->create(), $this->tokens());

        $raw = DB::table('users')->where('id', $user->id)->first();

        $this->assertNotNull($raw);
        $this->assertNotSame('access-token-value', $raw->strava_access_token);
        $this->assertNotSame('refresh-token-value', $raw->strava_refresh_token);
        $this->assertSame('access-token-value', $user->fresh()->strava_access_token);
        $this->assertSame('refresh-token-value', $user->fresh()->strava_refresh_token);
    }

    public function test_the_owners_identity_and_password_are_never_overwritten(): void
    {
        $owner = User::factory()->ownerWithPassword()->create(['name' => 'giarfa', 'email' => 'giarfa@heatmap.invalid']);
        $hash = $owner->password;

        $this->connect($owner, $this->tokens(athleteName: 'Ada Lovelace'));

        $fresh = $owner->fresh();
        $this->assertSame('giarfa', $fresh->name);
        $this->assertSame('giarfa@heatmap.invalid', $fresh->email);
        $this->assertSame('giarfa', $fresh->username);
        $this->assertSame($hash, $fresh->password);
    }

    private function connect(User $owner, StravaTokens $tokens): User
    {
        return (new ConnectStravaAthlete)($owner, $tokens);
    }

    private function tokens(
        string $accessToken = 'access-token-value',
        string $refreshToken = 'refresh-token-value',
        ?string $athleteName = 'Ada Lovelace',
    ): StravaTokens {
        return new StravaTokens(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: CarbonImmutable::createFromTimestampUTC(1_788_000_000),
            athleteId: '4821196',
            athleteName: $athleteName,
            scopes: ['read', 'activity:read_all'],
        );
    }
}
