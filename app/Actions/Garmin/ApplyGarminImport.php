<?php

namespace App\Actions\Garmin;

use App\Data\Garmin\GarminImportPlan;
use App\Data\Garmin\GarminImportSummary;
use App\Data\Garmin\ParsedGarminCsv;
use App\Enums\ConflictResolution;
use App\Models\User;
use App\Support\Heatmap\HeatmapGridCache;
use Illuminate\Support\Facades\DB;

/**
 * Writes a reviewed Garmin import into the owner: adds the new runs and applies
 * the Overwrite / Skip answer to each conflict, all or nothing.
 */
final readonly class ApplyGarminImport
{
    public function __construct(private HeatmapGridCache $heatmapGridCache) {}

    /**
     * @param  array<int, ConflictResolution>  $resolutions  Row index => the owner's answer
     */
    public function __invoke(User $athlete, GarminImportPlan $plan, array $resolutions, ParsedGarminCsv $parsed): GarminImportSummary
    {
        [$imported, $overwritten, $skipped] = DB::transaction(function () use ($athlete, $plan, $resolutions): array {
            foreach ($plan->newRows as $row) {
                $athlete->activities()->create($row->toAttributes());
            }

            $overwritten = 0;
            $skipped = 0;

            foreach ($plan->conflicts as $index => $conflict) {
                /*
                 * A missing answer is a Skip: the review pre-checks Skip, so the
                 * only safe reading of silence is "keep what is stored". The Form
                 * Request requires an answer per conflict; this is the backstop.
                 */
                if (($resolutions[$index] ?? ConflictResolution::Skip) !== ConflictResolution::Overwrite) {
                    $skipped++;

                    continue;
                }

                /*
                 * Every field the CSV carries wins, so the row really is Garmin's.
                 * The metrics follow the same rule except for one:
                 * toAttributes() leaves out a metric the file recorded as "--",
                 * so an empty cell keeps the stored value instead of blanking it.
                 */
                $conflict->stored->fill($conflict->row->toAttributes())->save();
                $overwritten++;
            }

            $this->fillEmptyMetricsOfAlreadyImportedRuns($plan);

            return [count($plan->newRows), $overwritten, $skipped];
        });

        /*
         * Forgotten for any import, not only one that added or
         * overwrote runs: a backfill-only import writes metrics the cached day
         * detail carries, and the detail must agree with the grid after it.
         */
        $this->heatmapGridCache->forgetAthlete($athlete);

        return new GarminImportSummary(
            imported: $imported,
            overwritten: $overwritten,
            skipped: $skipped,
            alreadyImported: count($plan->alreadyImported),
            ignoredByType: $parsed->ignoredByType,
            futureDated: $parsed->futureDated,
            invalid: $parsed->invalid,
        );
    }

    /**
     * A file row that is already imported (same start, distance and moving time) fills
     * only the metrics its stored run lacks.
     *
     * Re-importing the same export must stay a no-op, and a stored value — one the owner
     * overwrote — is never replaced by a re-read of the file. The
     * only thing a repeat import may add is a metric that was empty, which is how a run
     * imported before the metrics existed gets them from a fresh export.
     */
    private function fillEmptyMetricsOfAlreadyImportedRuns(GarminImportPlan $plan): void
    {
        foreach ($plan->alreadyImportedActivities as $index => $stored) {
            $emptyMetrics = array_filter(
                $plan->alreadyImported[$index]->metricAttributes(),
                static fn (int $value, string $column): bool => $stored->{$column} === null,
                ARRAY_FILTER_USE_BOTH,
            );

            if ($emptyMetrics !== []) {
                $stored->fill($emptyMetrics)->save();
            }
        }
    }
}
