<?php

namespace App\Support\Garmin;

use App\Data\Garmin\GarminImportConflict;
use App\Data\Garmin\GarminImportPlan;
use App\Data\Garmin\ParsedGarminCsv;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Plans the pending upload for the review, and reuses that plan on a reload.
 *
 * Planning matches every row against the stored runs of the upload's dates, and
 * the review is a GET a visitor can reload at will. The plan is therefore
 * remembered in the session next to the upload, with a stamp of everything the
 * planner read from the database, and handed back while the stamp still holds.
 * A new upload replaces the whole pending entry (PendingGarminImport::put()), so
 * it can never meet an older upload's plan.
 *
 * Reads only: the confirm never comes through here, it plans fresh under its lock.
 */
final readonly class RememberedGarminImportPlan
{
    public function __construct(
        private PlanGarminImport $planGarminImport,
        private PendingGarminImport $pendingImport,
    ) {}

    public function __invoke(?User $athlete, ParsedGarminCsv $parsed): GarminImportPlan
    {
        if ($athlete === null) {
            return ($this->planGarminImport)($athlete, $parsed->rows);
        }

        /*
         * Loaded once for the whole render: the stamp is made from it, the
         * planner takes it, and a reused plan names its stored runs from it, so
         * the review costs one activities query whether it plans or not.
         */
        $stored = $this->planGarminImport->storedRuns($athlete, $parsed->rows);
        $stamp = $this->stampOf($stored);
        $reused = $this->reuse($athlete, $parsed, $stored, $stamp);

        if ($reused !== null) {
            return $reused;
        }

        $plan = ($this->planGarminImport)($athlete, $parsed->rows, $stored);

        $this->pendingImport->rememberPlan([
            'athlete' => $athlete->id,
            'stamp' => $stamp,
            'new' => array_keys($plan->newRows),
            'conflicts' => array_map(
                static fn (GarminImportConflict $conflict): int => $conflict->stored->id,
                $plan->conflicts,
            ),
            'already_imported' => array_map(
                static fn (Activity $activity): int => $activity->id,
                $plan->alreadyImportedActivities,
            ),
        ]);

        return $plan;
    }

    /**
     * A hash of what the planner reads from the stored runs: for each, by id, the
     * columns it compares — the source (which runs can be "already imported"),
     * the dates and start, the distance and the moving time. Any run added,
     * removed or changed on the upload's dates moves it.
     *
     * Not `updated_at`: it has one-second resolution and a query-builder write
     * does not touch it, so a change could go unseen. A run's name and metrics are
     * left out on purpose, the planner never reads them, and the review renders
     * them from the loaded runs, so they still show as they are now.
     *
     * @param  Collection<int, Activity>  $stored
     */
    private function stampOf(Collection $stored): string
    {
        $columns = ['source', 'start_date_local', 'activity_date', 'distance_meters', 'moving_time_seconds'];

        return hash('sha256', (string) json_encode(
            $stored->sortKeys()->map(
                static fn (Activity $activity): array => array_map(
                    static fn (string $column): mixed => $activity->getRawOriginal($column),
                    $columns,
                ),
            )->all(),
        ));
    }

    /**
     * The remembered plan rebuilt from the parsed rows and the loaded stored
     * runs it names, or null when it is absent, was made for another athlete or
     * another database state, or no longer lines up with the upload (a row index
     * or a stored run that is gone): then the caller plans again.
     *
     * @param  Collection<int, Activity>  $stored
     */
    private function reuse(User $athlete, ParsedGarminCsv $parsed, Collection $stored, string $stamp): ?GarminImportPlan
    {
        $remembered = $this->pendingImport->rememberedPlan();

        if ($remembered === null || $remembered['athlete'] !== $athlete->id || $remembered['stamp'] !== $stamp) {
            return null;
        }

        $newRows = [];
        $conflicts = [];
        $alreadyImported = [];
        $alreadyImportedActivities = [];

        foreach ($remembered['new'] as $index) {
            if (! isset($parsed->rows[$index])) {
                return null;
            }

            $newRows[$index] = $parsed->rows[$index];
        }

        foreach ($remembered['conflicts'] as $index => $activityId) {
            if (! isset($parsed->rows[$index]) || ! $stored->has($activityId)) {
                return null;
            }

            $conflicts[$index] = new GarminImportConflict($index, $parsed->rows[$index], $stored[$activityId]);
        }

        foreach ($remembered['already_imported'] as $index => $activityId) {
            if (! isset($parsed->rows[$index]) || ! $stored->has($activityId)) {
                return null;
            }

            $alreadyImported[$index] = $parsed->rows[$index];
            $alreadyImportedActivities[$index] = $stored[$activityId];
        }

        return new GarminImportPlan($newRows, $conflicts, $alreadyImported, $athlete->id, $alreadyImportedActivities);
    }
}
