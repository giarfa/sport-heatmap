<?php

namespace App\Actions\Garmin;

use App\Data\Garmin\GarminImportPlan;
use App\Data\Garmin\GarminImportSummary;
use App\Data\Garmin\ParsedGarminCsv;
use App\Enums\ActivitySource;
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
        [$imported, $overwritten, $skipped, $enriched] = DB::transaction(function () use ($athlete, $plan, $resolutions): array {
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
                 * The metrics — the three run metrics and the extended ones of US-023 —
                 * follow the same rule except for one: toAttributes() leaves out a metric
                 * the file recorded as "--", so an empty cell keeps the stored value
                 * instead of blanking it.
                 *
                 * `source` records who wrote the run's current values, so it becomes the CSV;
                 * `garmin_activity_id` is the run's identity on Garmin and a CSV row has none,
                 * so it is neither in toAttributes() nor touched here — an API-synced run
                 * keeps it, and the next sync still recognises the run as its own.
                 */
                $conflict->stored->fill($conflict->row->toAttributes())
                    ->forceFill(['source' => ActivitySource::GarminCsv])
                    ->save();
                $overwritten++;
            }

            $enriched = $this->fillEmptyMetricsOfAlreadyImportedRuns($plan);

            return [count($plan->newRows), $overwritten, $skipped, $enriched];
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
            /*
             * The two counts partition the already-imported rows: an enriched run gained
             * something, an already-imported one changed nothing. A run is never in both,
             * so the summary adds up to the rows the file held.
             */
            alreadyImported: count($plan->alreadyImported) - $enriched,
            enriched: $enriched,
            ignoredByType: $parsed->ignoredByType,
            futureDated: $parsed->futureDated,
            invalid: $parsed->invalid,
        );
    }

    /**
     * A file row that is already imported (same start, distance and moving time) fills
     * only the metrics its stored run lacks — the three run metrics and the extended
     * ones alike, through this one path — and the runs that gained at least one are the
     * enriched count of the summary.
     *
     * Re-importing the same export must stay a no-op, and a stored value — one the owner
     * overwrote, or one a Garmin sync wrote — is never replaced by a re-read of the file.
     * The only thing a repeat import may add is a metric that was empty, which is how a
     * run imported before the metrics existed gets them from a fresh export. That is
     * also why a second re-import enriches nothing and writes nothing. The stored
     * `source` is left alone: an API run that gains a CSV-only Training Stress Score
     * stays an API run.
     *
     * No query per row: the stored runs are the ones the plan already holds.
     *
     * @return int How many runs gained at least one metric
     */
    private function fillEmptyMetricsOfAlreadyImportedRuns(GarminImportPlan $plan): int
    {
        $enriched = 0;

        foreach ($plan->alreadyImportedActivities as $index => $stored) {
            $emptyMetrics = array_filter(
                $plan->alreadyImported[$index]->metricAttributes(),
                static fn (int|float|string $value, string $column): bool => $stored->{$column} === null,
                ARRAY_FILTER_USE_BOTH,
            );

            if ($emptyMetrics !== []) {
                $stored->fill($emptyMetrics)->save();
                $enriched++;
            }
        }

        return $enriched;
    }
}
