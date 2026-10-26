<?php

namespace App\Http\Controllers;

use App\Data\Heatmap\WindowNavigation;
use App\Models\User;
use App\Support\Garmin\ResolveImportStatus;
use App\Support\Heatmap\DailyRunningDistance;
use App\Support\Heatmap\DailyRuns;
use App\Support\Heatmap\HeatmapBuilder;
use App\Support\Heatmap\HeatmapGridCache;
use App\Support\Heatmap\HeatmapWindow;
use App\Support\Heatmap\LeadInDistance;
use App\Support\Heatmap\OldestRunDate;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * The single screen of this product, private to the signed-in owner.
     *
     * An owner with no run gets the empty screen, which renders rather than
     * redirects: the dashboard is where a redirect would loop. It offers the
     * Garmin CSV import, the only way a run enters.
     */
    public function __invoke(
        Request $request,
        DailyRunningDistance $dailyDistance,
        DailyRuns $dailyRuns,
        LeadInDistance $leadInDistance,
        HeatmapBuilder $buildGrid,
        HeatmapGridCache $gridCache,
        OldestRunDate $oldestRunDate,
        ResolveImportStatus $resolveImportStatus,
    ): View {
        // Behind the `auth` route middleware, so the user is never null here.
        /** @var User $athlete */
        $athlete = $request->user();

        $oldestRun = $oldestRunDate($athlete);

        $today = now();

        if ($oldestRun === null) {
            return view('dashboard-empty', [
                'status' => $resolveImportStatus($athlete, HeatmapWindow::current($today)),
            ]);
        }

        $window = HeatmapWindow::fromRequestedEnd($this->requestedEnd($request), $today);

        /*
         * The cache key is per athlete. The day detail's runs are part of the same
         * cached grid, so an import that forgets the grid forgets the detail with
         * it — they cannot disagree.
         */

        /*
         * The weekly trend is built with the grid and cached inside it, so its one extra
         * query — the lead-in weeks the first 4-week averages reach back into — runs only
         * on a cache miss, inside this closure. A hit costs the chart nothing, and an
         * import that forgets the grid forgets the chart with it: there is no second
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
        $canHoldRuns = $window->isCurrent($today) || $window->endsOn->greaterThanOrEqualTo($oldestRun);

        $grid = $canHoldRuns ? $gridCache->remember($athlete, $window, $today, $build) : $build();

        /*
         * Resolved outside the grid cache, deliberately: a cached grid is
         * legitimate (US-004), but a cached "last import 6 hours ago" would
         * quietly lie — the import status is read fresh on every request
         * regardless of whether the grid was.
         *
         * It always reads the CURRENT window, never the one shown: the status
         * describes the data, not the period on screen, so the chip and the
         * import card are the same whichever window the reader has navigated to.
         * There is no warning state: nothing runs in the background, so nothing
         * can fall behind.
         */
        $status = $resolveImportStatus($athlete, HeatmapWindow::current($today));

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
