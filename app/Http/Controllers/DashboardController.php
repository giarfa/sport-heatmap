<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Heatmap\DailyRunningDistance;
use App\Support\Heatmap\HeatmapBuilder;
use App\Support\Heatmap\HeatmapGridCache;
use App\Support\Heatmap\HeatmapWindow;
use App\Support\Sync\ResolveSyncStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * The single screen of this product.
     *
     * A signed-out visit renders the connect screen rather than redirecting to a
     * login page — there is no login page, and a redirect loop is the failure
     * mode this route exists to avoid.
     */
    public function __invoke(
        DailyRunningDistance $dailyDistance,
        HeatmapBuilder $buildGrid,
        HeatmapGridCache $gridCache,
        ResolveSyncStatus $resolveSyncStatus,
    ): View {
        if (! Auth::check()) {
            return view('strava.connect');
        }

        /** @var User $athlete */
        $athlete = Auth::user();
        $window = HeatmapWindow::current();
        $today = now();

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
