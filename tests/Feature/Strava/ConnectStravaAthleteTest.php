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

    public function test_a_first_connect_creates_exactly_one_athlete(): void
    {
        $user = $this->connect($this->tokens());

        $this->assertSame(1, User::query()->count());
        $this->assertSame('4821196', $user->strava_athlete_id);
        $this->assertSame('access-token-value', $user->strava_access_token);
        $this->assertSame('refresh-token-value', $user->strava_refresh_token);
        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertTrue(
            CarbonImmutable::createFromTimestampUTC(1_788_000_000)->equalTo($user->strava_token_expires_at),
        );
    }

    public function test_reconnecting_updates_the_existing_athlete_instead_of_adding_one(): void
    {
        $this->connect($this->tokens());

        $reconnected = $this->connect($this->tokens(
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
        $user = $this->connect($this->tokens());

        $raw = DB::table('users')->where('id', $user->id)->first();

        $this->assertNotNull($raw);
        $this->assertNotSame('access-token-value', $raw->strava_access_token);
        $this->assertNotSame('refresh-token-value', $raw->strava_refresh_token);
        $this->assertSame('access-token-value', $user->fresh()->strava_access_token);
        $this->assertSame('refresh-token-value', $user->fresh()->strava_refresh_token);
    }

    public function test_a_synthetic_email_is_assigned_and_never_overwritten(): void
    {
        $user = $this->connect($this->tokens());

        $this->assertSame('athlete-4821196@strava.invalid', $user->email);
        $this->assertNotNull($user->email_verified_at);

        $this->connect($this->tokens());

        $this->assertSame('athlete-4821196@strava.invalid', $user->fresh()->email);
    }

    public function test_a_blank_athlete_name_falls_back_to_the_athlete_id(): void
    {
        $user = $this->connect($this->tokens(athleteName: null));

        $this->assertSame('Athlete 4821196', $user->name);
    }

    public function test_no_password_is_ever_set(): void
    {
        $this->assertNull($this->connect($this->tokens())->fresh()->password);
    }

    private function connect(StravaTokens $tokens): User
    {
        return (new ConnectStravaAthlete)($tokens);
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
