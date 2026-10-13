<?php

namespace App\Support\Heatmap;

use App\Data\Heatmap\HeatmapGrid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

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
     * An entry that cannot be restored as a HeatmapGrid counts as a miss and is
     * overwritten. A serializing store restores a class missing from
     * `cache.serializable_classes` as __PHP_Incomplete_Class, and throws while
     * unserializing when the missing class, an enum case, or a date sits inside
     * the grid — so an entry written by an older shape of the grid would
     * otherwise fail every request until it expires.
     *
     * @param  Closure(): HeatmapGrid  $build
     */
    public function remember(User $athlete, HeatmapWindow $window, CarbonInterface $today, Closure $build): HeatmapGrid
    {
        $key = $this->key($athlete, $window, $today);

        try {
            $cached = $this->cache->get($key);
        } catch (Throwable $exception) {
            report($exception);
            $cached = null;
        }

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

        /*
         * The "v2" segment is the shape of the cached object. A grid cached before the
         * day detail existed has DayCell objects with no `runs` property, and
         * unserialize() restores a readonly property it never saw as uninitialized — the
         * entry reads back as a HeatmapGrid and then fails on first access, which the
         * instanceof check in remember() cannot catch. Bump this segment whenever a
         * cached class gains a property; old entries are simply never read again and
         * expire on their own.
         */
        return "heatmap:grid:v2:{$athlete->id}:{$window->startsOn->toDateString()}:{$today}";
    }
}
