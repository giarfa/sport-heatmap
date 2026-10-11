<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Heatmap\DailyRunningDistance;
use App\Support\Heatmap\HeatmapBuilder;
use App\Support\Heatmap\HeatmapGridCache;
use App\Support\Heatmap\HeatmapWindow;
use App\Support\Strava\ResolveDisplayedAthlete;
use App\Support\Sync\ResolveSyncStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * The single screen of this product, public and read-only.
     *
     * The signed-in athlete sees their own map. A signed-out visitor sees the
     * displayed athlete's — the first connected id of the allowlist — and only
     * falls back to the connect screen when nobody has connected yet. That
     * fallback renders rather than redirects: there is no login page, and a
     * redirect loop is the failure mode this route exists to avoid.
     */
    public function __invoke(
        DailyRunningDistance $dailyDistance,
        HeatmapBuilder $buildGrid,
        HeatmapGridCache $gridCache,
        ResolveSyncStatus $resolveSyncStatus,
        ResolveDisplayedAthlete $resolveDisplayedAthlete,
    ): View {
        /*
         * The guest branch is read-only by structure, not by care: the
         * strava.fresh middleware is a no-op for guests, nothing below calls
         * EnsureFreshAccessToken or StravaClient, and the resolver selects no
         * token column. A guest request therefore cannot refresh a token,
         * reach Strava, or write a row.
         */
        /** @var User|null $athlete */
        $athlete = Auth::user() ?? $resolveDisplayedAthlete();

        if ($athlete === null) {
            return view('strava.connect');
        }

        $window = HeatmapWindow::current();
        $today = now();

        /*
         * The cache key is per athlete, not per viewer, so guests and the
         * signed-in owner share one cached grid — a guest visit never costs a
         * rebuild the owner has already paid for, and vice versa.
         */
        $grid = $gridCache->remember(
            $athlete,
            $window,
            $today,
            fn () => $buildGrid($window, $dailyDistance($athlete, $window), $today),
        );

        /*
         * Resolved outside the grid cache, deliberately: a cached grid is
         * legitimate (US-004), but a cached "synced 6 hours ago" is the
         * failure mode FR-007 exists to prevent — the sync status has to be
         * read fresh on every request regardless of whether the grid was.
         */
        $status = $resolveSyncStatus($athlete, $window, $today);

        return view('dashboard', ['grid' => $grid, 'status' => $status]);
    }
}
