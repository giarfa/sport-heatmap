<?php

namespace Tests\Unit\Support\Garmin;

use App\Exceptions\Garmin\GarminCsvRejectedException;
use App\Support\Garmin\GarminCsvColumns;
use Tests\TestCase;

class GarminCsvColumnsTest extends TestCase
{
    private const IT_REQUIRED = ['Tipo di attività', 'Data', 'Titolo', 'Distanza', 'Tempo in movimento'];

    private const EN_REQUIRED = ['Activity Type', 'Date', 'Title', 'Distance', 'Moving Time'];

    public function test_italian_extended_headers_are_located_wherever_they_sit(): void
    {
        $columns = GarminCsvColumns::detect(['Quota massima', 'Calorie', ...self::IT_REQUIRED, 'Passi', 'Training Stress Score®', 'Bilanciamento TCS medio']);

        $this->assertSame(0, $columns['max_elevation']);
        $this->assertSame(1, $columns['calories']);
        $this->assertSame(7, $columns['steps']);
        $this->assertSame(8, $columns['training_stress_score']);
        $this->assertSame(9, $columns['gct_balance']);
        $this->assertSame(2, $columns['type']);
    }

    public function test_english_extended_headers_are_located(): void
    {
        $columns = GarminCsvColumns::detect([...self::EN_REQUIRED, 'Calories', 'Max HR', 'Number of Laps', 'Avg GCT Balance']);

        $this->assertSame(5, $columns['calories']);
        $this->assertSame(6, $columns['max_hr']);
        $this->assertSame(7, $columns['laps']);
        $this->assertSame(8, $columns['gct_balance']);
    }

    public function test_both_spellings_of_the_english_elevation_headers_are_accepted(): void
    {
        $short = GarminCsvColumns::detect([...self::EN_REQUIRED, 'Min Elev', 'Max Elev']);
        $long = GarminCsvColumns::detect([...self::EN_REQUIRED, 'Min Elevation', 'Max Elevation']);

        $this->assertSame([5, 6], [$short['min_elevation'], $short['max_elevation']]);
        $this->assertSame([5, 6], [$long['min_elevation'], $long['max_elevation']]);
    }

    public function test_a_file_without_extended_headers_is_still_accepted_and_maps_none(): void
    {
        $columns = GarminCsvColumns::detect(self::IT_REQUIRED);

        $this->assertSame([], array_values(array_intersect(GarminCsvColumns::EXTENDED_KEYS, array_keys($columns))));
    }

    public function test_extended_headers_alone_never_make_a_file_recognisable(): void
    {
        $this->expectException(GarminCsvRejectedException::class);

        GarminCsvColumns::detect(['Calorie', 'FC max', 'Passi', 'Numero di Lap', 'Quota minima', 'Training Stress Score®']);
    }

    public function test_extended_headers_do_not_stand_in_for_a_missing_required_column(): void
    {
        try {
            GarminCsvColumns::detect(['Tipo di attività', 'Data', 'Titolo', 'Distanza', 'Calorie', 'Passi', 'Quota minima']);
            $this->fail('A missing moving time must be reported.');
        } catch (GarminCsvRejectedException $exception) {
            $this->assertStringContainsString('Tempo in movimento', $exception->getMessage());
        }
    }
}
