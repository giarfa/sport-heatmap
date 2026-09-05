<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * A Strava token set, carried across the OAuth boundary so no array shape from
 * Strava's API leaks into controllers or Eloquent.
 */
final readonly class StravaTokens
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public CarbonImmutable $expiresAt,
        public ?string $athleteId = null,
        public ?string $athleteName = null,
        public array $scopes = [],
    ) {}

    /**
     * Build from the authorization-code exchange response.
     *
     * Strava embeds the athlete object in this response, which is what lets the
     * allowlist be checked before anything at all is persisted.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromExchangeResponse(array $payload, string $grantedScope): self
    {
        /** @var array<string, mixed> $athlete */
        $athlete = is_array($payload['athlete'] ?? null) ? $payload['athlete'] : [];

        $name = trim(sprintf(
            '%s %s',
            is_scalar($athlete['firstname'] ?? null) ? (string) $athlete['firstname'] : '',
            is_scalar($athlete['lastname'] ?? null) ? (string) $athlete['lastname'] : '',
        ));

        return new self(
            accessToken: (string) $payload['access_token'],
            refreshToken: (string) $payload['refresh_token'],
            expiresAt: CarbonImmutable::createFromTimestampUTC((int) $payload['expires_at']),
            athleteId: isset($athlete['id']) && is_scalar($athlete['id']) ? (string) $athlete['id'] : null,
            athleteName: $name === '' ? null : $name,
            scopes: self::parseScopes($grantedScope),
        );
    }

    /**
     * Build from a refresh response.
     *
     * The refresh response carries no athlete object and no scope, so the athlete
     * identity and the granted scopes are carried forward from the previous set.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromRefreshResponse(array $payload, self $previous): self
    {
        return new self(
            accessToken: (string) $payload['access_token'],
            refreshToken: (string) $payload['refresh_token'],
            expiresAt: CarbonImmutable::createFromTimestampUTC((int) $payload['expires_at']),
            athleteId: $previous->athleteId,
            athleteName: $previous->athleteName,
            scopes: $previous->scopes,
        );
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, strict: true);
    }

    /**
     * @return array<int, string>
     */
    private static function parseScopes(string $grantedScope): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $grantedScope)),
            static fn (string $scope): bool => $scope !== '',
        ));
    }
}
