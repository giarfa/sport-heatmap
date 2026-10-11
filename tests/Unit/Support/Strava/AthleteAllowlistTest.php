<?php

namespace Tests\Unit\Support\Strava;

use App\Support\Strava\AthleteAllowlist;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The allowlist is the only access control in this product, so the interesting
 * cases are the ones where it might accidentally say yes.
 */
class AthleteAllowlistTest extends TestCase
{
    public function test_a_listed_athlete_is_allowed(): void
    {
        Config::set('strava.allowed_athlete_ids', ['4821196', '1234']);

        $this->assertTrue($this->allowlist()->allows('4821196'));
    }

    public function test_an_unlisted_athlete_is_rejected(): void
    {
        Config::set('strava.allowed_athlete_ids', ['4821196']);

        $this->assertFalse($this->allowlist()->allows('9999999'));
    }

    public function test_an_empty_allowlist_rejects_everyone(): void
    {
        Config::set('strava.allowed_athlete_ids', []);

        foreach (['4821196', '0', ''] as $athleteId) {
            $this->assertFalse(
                $this->allowlist()->allows($athleteId),
                "An empty allowlist must reject \"{$athleteId}\".",
            );
        }
    }

    public function test_a_numeric_prefix_is_not_a_match(): void
    {
        Config::set('strava.allowed_athlete_ids', ['1234']);

        $this->assertFalse($this->allowlist()->allows('123'));
        $this->assertFalse($this->allowlist()->allows('12345'));
    }

    public function test_a_missing_allowlist_configuration_rejects_everyone(): void
    {
        Config::set('strava.allowed_athlete_ids', []);

        $this->assertFalse($this->allowlist()->allows('4821196'));
    }

    public function test_athlete_ids_keep_the_configured_order(): void
    {
        Config::set('strava.allowed_athlete_ids', ['222', '111', '333']);

        $this->assertSame(['222', '111', '333'], $this->allowlist()->athleteIds());
    }

    private function allowlist(): AthleteAllowlist
    {
        return new AthleteAllowlist;
    }
}
