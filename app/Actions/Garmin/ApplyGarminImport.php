<?php

namespace App\Actions\Garmin;

use App\Data\Garmin\GarminImportPlan;
use App\Data\Garmin\GarminImportSummary;
use App\Data\Garmin\ParsedGarminCsv;
use App\Enums\ConflictResolution;
use App\Models\User;
use App\Support\Heatmap\HeatmapGridCache;
use App\Support\Heatmap\HeatmapWindow;
use Illuminate\Support\Facades\DB;

/**
 * Writes a reviewed Garmin import: adds the new runs and applies the owner's
 * Overwrite / Skip answer to each conflict, all or nothing.
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
                 * Every field the CSV carries wins, and source becomes garmin_csv,
                 * so the row really is Garmin's. strava_activity_id is not among
                 * them and stays: it is how the nightly sync recognises this twin
                 * and leaves it alone.
                 */
                $conflict->stored->fill($conflict->row->toAttributes())->save();
                $overwritten++;
            }

            return [count($plan->newRows), $overwritten, $skipped];
        });

        $this->heatmapGridCache->forget($athlete, HeatmapWindow::current(), now());

        return new GarminImportSummary(
            imported: $imported,
            overwritten: $overwritten,
            skipped: $skipped,
            alreadyImported: count($plan->alreadyImported),
            ignoredByType: $parsed->ignoredByType,
            outsideWindow: $parsed->outsideWindow,
            invalid: $parsed->invalid,
        );
    }
}
