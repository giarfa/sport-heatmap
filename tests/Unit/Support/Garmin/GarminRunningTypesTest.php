<?php

namespace Tests\Unit\Support\Garmin;

use App\Enums\SportType;
use App\Support\Garmin\GarminRunningTypes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GarminRunningTypesTest extends TestCase
{
    /**
     * @return array<string, array{string, SportType}>
     */
    public static function runningTypeKeys(): array
    {
        return [
            'running' => ['running', SportType::Run],
            'street_running' => ['street_running', SportType::Run],
            'track_running' => ['track_running', SportType::Run],
            'indoor_running' => ['indoor_running', SportType::Run],
            'treadmill_running' => ['treadmill_running', SportType::Run],
            'ultra_run' => ['ultra_run', SportType::Run],
            'obstacle_run' => ['obstacle_run', SportType::Run],
            'trail_running' => ['trail_running', SportType::TrailRun],
            'virtual_run' => ['virtual_run', SportType::VirtualRun],
        ];
    }

    #[DataProvider('runningTypeKeys')]
    public function test_every_api_running_type_key_maps_to_its_sport_type(string $typeKey, SportType $expected): void
    {
        $this->assertSame($expected, GarminRunningTypes::sportTypeForTypeKey($typeKey));
    }

    #[DataProvider('notRunningTypeKeys')]
    public function test_any_other_type_key_is_not_a_run(string $typeKey): void
    {
        $this->assertNull(GarminRunningTypes::sportTypeForTypeKey($typeKey));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notRunningTypeKeys(): array
    {
        return [
            'cycling' => ['cycling'],
            'hiking' => ['hiking'],
            'walking' => ['walking'],
            'strength' => ['strength_training'],
            'empty' => [''],
            'a CSV label is not an API key' => ['Corsa'],
            'the key is exact, not case-folded' => ['Running'],
        ];
    }
}
