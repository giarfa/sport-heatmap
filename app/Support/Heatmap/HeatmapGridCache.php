<?php

namespace App\Support\Heatmap;

use App\Data\Heatmap\HeatmapGrid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Cache\Repository;

/**
 * Owns the cached grid's key and TTL, so the dashboard controller and the sync
 * command cannot drift apart on the format.
 */
final readonly class HeatmapGridCache
{
    public function __construct(
        private Repository $cache,
    ) {}

    /**
     * Return the cached grid, or build and store it on a miss.
     *
     * An entry that is not a HeatmapGrid counts as a miss and is overwritten.
     * A serializing store restores a class missing from
     * `cache.serializable_classes` as __PHP_Incomplete_Class, so an entry
     * written before a class joined the grid — or the allowlist — would
     * otherwise fail every request until it expires.
     *
     * @param  Closure(): HeatmapGrid  $build
     */
    public function remember(User $athlete, HeatmapWindow $window, CarbonInterface $today, Closure $build): HeatmapGrid
    {
        $key = $this->key($athlete, $window, $today);
        $cached = $this->cache->get($key);

        if ($cached instanceof HeatmapGrid) {
            return $cached;
        }

        $grid = $build();
        $this->cache->put($key, $grid, (int) config('heatmap.grid_cache_ttl'));

        return $grid;
    }

    /**
     * Drop this athlete's cached grid for the given day, so a mid-day sync is
     * visible on the next request.
     */
    public function forget(User $athlete, HeatmapWindow $window, CarbonInterface $today): void
    {
        $this->cache->forget($this->key($athlete, $window, $today));
    }

    /**
     * The grid changes at the calendar-day rollover, not only when a sync runs.
     * Keying on the window alone would let yesterday's cached grid survive
     * midnight and render today as a future cell — stale data presented as
     * current, the one failure mode the PRD names as fatal. Including $today
     * in the key is what makes the cache self-invalidate at midnight.
     */
    private function key(User $athlete, HeatmapWindow $window, CarbonInterface $today): string
    {
        $today = CarbonImmutable::instance($today)->toDateString();

        return "heatmap:grid:{$athlete->id}:{$window->startsOn->toDateString()}:{$today}";
    }
}
