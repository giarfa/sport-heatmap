<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guards the production dependency set against developer tooling.
 *
 * `andreapollastri/larapilot` registers a dashboard under `/larapilot/*` that reads `.env`,
 * dumps the database and writes files, and it pulls `laravel/boost` and `laravel/mcp` in
 * with it. Listed under `require`, all of it installs on a `composer install --no-dev`
 * host and its exposure depends on `APP_ENV` alone (v2.0.0 assessment, OWASP A05/A01).
 */
class ComposerDependenciesTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function developmentOnlyRequirementProvider(): array
    {
        return [
            'larapilot dashboard' => ['andreapollastri/larapilot'],
            'boost' => ['laravel/boost'],
            'pail' => ['laravel/pail'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function developmentToolingProvider(): array
    {
        return [
            'larapilot dashboard' => ['andreapollastri/larapilot'],
            'boost' => ['laravel/boost'],
            'mcp' => ['laravel/mcp'],
        ];
    }

    #[DataProvider('developmentOnlyRequirementProvider')]
    public function test_development_only_packages_are_not_production_requirements(string $package): void
    {
        $manifest = $this->readJson(base_path('composer.json'));

        $this->assertArrayNotHasKey(
            $package,
            $manifest['require'] ?? [],
            "{$package} is development tooling: under `require` it installs on a `--no-dev` host. Move it to `require-dev`.",
        );
    }

    #[DataProvider('developmentToolingProvider')]
    public function test_development_tooling_is_locked_as_a_development_package(string $package): void
    {
        $lock = $this->readJson(base_path('composer.lock'));

        $productionPackages = array_column($lock['packages'] ?? [], 'name');

        $this->assertNotContains(
            $package,
            $productionPackages,
            "{$package} is locked in `packages`, so `composer install --no-dev` installs it.",
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
