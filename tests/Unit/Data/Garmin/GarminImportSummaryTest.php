<?php

namespace Tests\Unit\Data\Garmin;

use App\Data\Garmin\GarminImportSummary;
use Tests\TestCase;

class GarminImportSummaryTest extends TestCase
{
    private function summary(int $alreadyImported = 4, int $enriched = 3): GarminImportSummary
    {
        return new GarminImportSummary(
            imported: 1,
            overwritten: 2,
            skipped: 0,
            alreadyImported: $alreadyImported,
            enriched: $enriched,
            ignoredByType: ['Escursionismo' => 3],
            futureDated: 0,
            invalid: [['line' => 7, 'reason' => 'unreadable date']],
        );
    }

    public function test_the_enriched_count_survives_the_session_round_trip(): void
    {
        $summary = $this->summary();

        $this->assertSame(3, $summary->toSessionArray()['enriched']);
        $this->assertEquals($summary, GarminImportSummary::fromSessionArray($summary->toSessionArray()));
    }

    public function test_a_summary_flashed_before_enrichment_existed_reads_zero_enriched(): void
    {
        $flashed = $this->summary()->toSessionArray();
        unset($flashed['enriched']);

        $rebuilt = GarminImportSummary::fromSessionArray($flashed);

        $this->assertSame(0, $rebuilt->enriched);
        $this->assertSame(4, $rebuilt->alreadyImported);
    }
}
