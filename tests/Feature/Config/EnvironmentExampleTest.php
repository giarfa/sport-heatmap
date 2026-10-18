<?php

namespace Tests\Feature\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guards `.env.example` against development defaults.
 *
 * `composer setup` copies the file to `.env`, so every value in it is what a freshly built
 * host runs with: a debug page on any 500, one never-rotating debug log, cookies that also
 * travel over plain HTTP (v2.0.0 assessment, OWASP A05/A09). The file is read directly —
 * the running suite takes its values from `phpunit.xml`, not from `.env.example`.
 */
class EnvironmentExampleTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function productionValueProvider(): array
    {
        return [
            'environment' => ['APP_ENV', 'production', 'it would run with the development affordances on'],
            'debug page' => ['APP_DEBUG', 'false', 'any 500 would render a stack trace with environment details'],
            'log stack' => ['LOG_STACK', 'daily', 'it would write one log file that never rotates'],
            'log level' => ['LOG_LEVEL', 'warning', 'it would write debug-level request detail to disk'],
            'secure cookie' => ['SESSION_SECURE_COOKIE', 'true', 'its sign-in cookies would also be sent over plain HTTP'],
        ];
    }

    #[DataProvider('productionValueProvider')]
    public function test_the_example_ships_the_production_value(string $key, string $expected, string $consequence): void
    {
        $values = $this->exampleValues();

        $this->assertArrayHasKey($key, $values, "{$key} is missing from .env.example.");
        $this->assertSame(
            $expected,
            $values[$key],
            "{$key} must be `{$expected}` in .env.example: a host built from it inherits the value, so {$consequence}.",
        );
    }

    public function test_the_example_app_url_is_https(): void
    {
        $values = $this->exampleValues();

        $this->assertArrayHasKey('APP_URL', $values, 'APP_URL is missing from .env.example.');
        $this->assertSame(
            'https',
            parse_url($values['APP_URL'], PHP_URL_SCHEME),
            'APP_URL must be an https URL in .env.example: the OAuth callback and generated links are built from it.',
        );
    }

    /**
     * @return array<string, string>
     */
    private function exampleValues(): array
    {
        $values = [];

        foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $values[trim($key)] = trim($value, " \t\"");
        }

        return $values;
    }
}
