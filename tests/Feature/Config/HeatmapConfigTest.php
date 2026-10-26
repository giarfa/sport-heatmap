<?php

namespace Tests\Feature\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HeatmapConfigTest extends TestCase
{
    /**
     * @return array<string, array{string|null, string}>
     */
    public static function timezones(): array
    {
        return [
            'a valid zone is kept' => ['America/New_York', 'America/New_York'],
            'unset falls back' => [null, 'Europe/Rome'],
            'blank falls back' => ['', 'Europe/Rome'],
            'an unknown zone falls back' => ['Mars/Phobos', 'Europe/Rome'],
        ];
    }

    #[DataProvider('timezones')]
    public function test_the_display_timezone_is_always_a_real_zone(?string $value, string $expected): void
    {
        $previous = $_ENV['HEATMAP_DISPLAY_TIMEZONE'] ?? null;

        if ($value === null) {
            unset($_ENV['HEATMAP_DISPLAY_TIMEZONE'], $_SERVER['HEATMAP_DISPLAY_TIMEZONE']);
            putenv('HEATMAP_DISPLAY_TIMEZONE');
        } else {
            $_ENV['HEATMAP_DISPLAY_TIMEZONE'] = $_SERVER['HEATMAP_DISPLAY_TIMEZONE'] = $value;
            putenv("HEATMAP_DISPLAY_TIMEZONE={$value}");
        }

        try {
            $config = require base_path('config/heatmap.php');

            $this->assertSame($expected, $config['display_timezone']);
        } finally {
            if ($previous === null) {
                unset($_ENV['HEATMAP_DISPLAY_TIMEZONE'], $_SERVER['HEATMAP_DISPLAY_TIMEZONE']);
                putenv('HEATMAP_DISPLAY_TIMEZONE');
            } else {
                $_ENV['HEATMAP_DISPLAY_TIMEZONE'] = $_SERVER['HEATMAP_DISPLAY_TIMEZONE'] = $previous;
                putenv("HEATMAP_DISPLAY_TIMEZONE={$previous}");
            }
        }
    }
}
