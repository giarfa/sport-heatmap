<?php

namespace App\Http\Controllers;

use App\Data\Heatmap\WindowNavigation;
use App\Models\User;
use App\Support\Heatmap\DailyRunningDistance;
use App\Support\Heatmap\DailyRuns;
use App\Support\Heatmap\HeatmapBuilder;
use App\Support\Heatmap\HeatmapGridCache;
use App\Support\Heatmap\HeatmapWindow;
use App\Support\Heatmap\OldestRunDate;
use App\Support\Strava\ResolveDisplayedAthlete;
use App\Support\Sync\ResolveSyncStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * The single screen of this product, public and read-only.
     *
     * The signed-in athlete sees their own map. A signed-out visitor sees the
     * displayed athlete's — the first connected id of the allowlist, else the
     * lowest-id stored athlete with an activity — and only gets the connect
     * screen when no stored athlete has any activity. The connect screen
     * renders rather than redirects: there is no login page, and a redirect
     * loop is the failure mode this route exists to avoid.
     */
    public function __invoke(
        Request $request,
        DailyRunningDistance $dailyDistance,
        DailyRuns $dailyRuns,
        HeatmapBuilder $buildGrid,
        HeatmapGridCache $gridCache,
        OldestRunDate $oldestRunDate,
        ResolveSyncStatus $resolveSyncStatus,
        ResolveDisplayedAthlete $resolveDisplayedAthlete,
    ): View {
        /*
         * The guest branch is read-only by structure, not by care: the
         * strava.fresh middleware is a no-op for guests, nothing below calls
         * EnsureFreshAccessToken or StravaClient, and the resolver selects no
         * token column. A guest request therefore cannot refresh a token,
         * reach Strava, or write a domain row — only the session and cache
         * stores write, as they do for any request.
         */
        /** @var User|null $athlete */
        $athlete = Auth::user() ?? $resolveDisplayedAthlete();

        if ($athlete === null) {
            return view('strava.connect');
        }

        $today = now();
        $window = HeatmapWindow::fromRequestedEnd($this->requestedEnd($request), $today);

        /*
         * The cache key is per athlete, not per viewer, so guests and the
         * signed-in owner share one cached grid — a guest visit never costs a
         * rebuild the owner has already paid for, and vice versa. The day detail's
         * runs are part of the same cached grid, so a sync or an import that forgets
         * the grid forgets the detail with it — they cannot disagree.
         */
        $grid = $gridCache->remember(
            $athlete,
            $window,
            $today,
            fn () => $buildGrid($window, $dailyDistance($athlete, $window), $today, $dailyRuns($athlete, $window)),
        );

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

        $navigation = WindowNavigation::for($window, $today, $oldestRunDate($athlete));

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
