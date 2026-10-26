<?php

namespace App\Http\Controllers;

use App\Data\Heatmap\WindowNavigation;
use App\Models\User;
use App\Support\Heatmap\DailyRunningDistance;
use App\Support\Heatmap\DailyRuns;
use App\Support\Heatmap\HeatmapBuilder;
use App\Support\Heatmap\HeatmapGridCache;
use App\Support\Heatmap\HeatmapWindow;
use App\Support\Heatmap\LeadInDistance;
use App\Support\Heatmap\OldestRunDate;
use App\Support\Sync\ResolveSyncStatus;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * The single screen of this product, private to the signed-in owner.
     *
     * It shows the owner's runs of either source. An owner with no run and no
     * Strava connection gets the connect screen, which renders rather than
     * redirects: the dashboard is where a redirect would loop. An owner who has
     * connected Strava keeps the dashboard, so the first sync shows its skeleton.
     */
    public function __invoke(
        Request $request,
        DailyRunningDistance $dailyDistance,
        DailyRuns $dailyRuns,
        LeadInDistance $leadInDistance,
        HeatmapBuilder $buildGrid,
        HeatmapGridCache $gridCache,
        OldestRunDate $oldestRunDate,
        ResolveSyncStatus $resolveSyncStatus,
    ): View {
        // Behind the `auth` route middleware, so the user is never null here.
        /** @var User $athlete */
        $athlete = $request->user();

        $oldestRun = $oldestRunDate($athlete);

        if ($oldestRun === null && $athlete->strava_athlete_id === null) {
            return view('strava.connect');
        }

        $today = now();
        $window = HeatmapWindow::fromRequestedEnd($this->requestedEnd($request), $today);

        /*
         * The cache key is per athlete. The day detail's runs are part of the same
         * cached grid, so a sync or an import that forgets the grid forgets the
         * detail with it — they cannot disagree.
         */

        /*
         * The weekly trend is built with the grid and cached inside it, so its one extra
         * query — the lead-in weeks the first 4-week averages reach back into — runs only
         * on a cache miss, inside this closure. A hit costs the chart nothing, and a sync
         * or import that forgets the grid forgets the chart with it: there is no second
         * cache that could outlive a write.
         */
        $build = fn () => $buildGrid(
            $window,
            $dailyDistance($athlete, $window),
            $today,
            $dailyRuns($athlete, $window),
            $leadInDistance($athlete, $window),
            $oldestRun,
        );

        /*
         * Only windows that can hold a run are cached, plus the current one. `to` accepts any
         * past date, and every distinct week is a distinct cache key: without this a request
         * could fill the cache table with one empty grid per week back to year 0. A window
         * that ends before the oldest run is empty by construction, costs two small queries,
         * and is never linked to by Previous.
         */
        $canHoldRuns = $window->isCurrent($today)
            || ($oldestRun !== null && $window->endsOn->greaterThanOrEqualTo($oldestRun));

        $grid = $canHoldRuns ? $gridCache->remember($athlete, $window, $today, $build) : $build();

        /*
         * Resolved outside the grid cache, deliberately: a cached grid is
         * legitimate (US-004), but a cached "synced 6 hours ago" is the
         * failure mode FR-007 exists to prevent — the sync status has to be
         * read fresh on every request regardless of whether the grid was.
         *
         * It always reads the CURRENT window, never the one shown: the status
         * describes the sync, not the period on screen, so the chip, the warning,
         * the sync card and the first-sync skeleton must be the same whichever
         * window the reader has navigated to.
         */
        $status = $resolveSyncStatus($athlete, HeatmapWindow::current($today), $today);

        $navigation = WindowNavigation::for($window, $today, $oldestRun);

        return view('dashboard', ['grid' => $grid, 'status' => $status, 'navigation' => $navigation]);
    }

    /**
     * The `to` query value as a string, or '' for anything else: `?to[]=x` arrives as an
     * array, and a plain (string) cast of it is a 500. HeatmapWindow decides what a string
     * is worth.
     */
    private function requestedEnd(Request $request): string
    {
        $requested = $request->query('to');

        return is_string($requested) ? $requested : '';
    }
}
