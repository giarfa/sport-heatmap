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
        $generation = $this->generation($athlete);

        /*
         * A generation that cannot be read means the store is failing, and a grid
         * stored or served under a guessed generation could outlive the write that
         * should have retired it — so this request builds the grid and leaves the
         * cache alone.
         */
        if ($generation === null) {
            return $build();
        }

        $key = $this->key($athlete, $window, $today, $generation);

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
     * Retire every cached window of this athlete, so a sync or an import is
     * visible on every window the next time it is opened.
     *
     * The cache has no tags (`cache.default` is `database`), and the windows an
     * athlete may have cached are as many as there are weeks since their first
     * run, so they cannot be forgotten one by one. Bumping the generation the key
     * carries makes every existing entry unreachable at once; the orphans expire
     * through `grid_cache_ttl`.
     */
    public function forgetAthlete(User $athlete): void
    {
        $generationKey = $this->generationKey($athlete);

        /*
         * The counter is created before it is bumped: on a missing key the database
         * store's increment() answers false, and writing 1 in that branch would let two
         * overlapping writes (an import during the nightly sync) both land on 1, so a grid
         * built between them would survive the second write. add() is a no-op once the key
         * exists, and increment() is atomic.
         *
         * A store that fails here is reported, not thrown: the write it follows is already
         * committed, and a 500 for an import that was saved is worse than a grid that is
         * served until grid_cache_ttl.
         */
        try {
            $this->cache->add($generationKey, 0);
            $this->cache->increment($generationKey);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Null when the counter cannot be read; 0 when it has never been bumped.
     */
    private function generation(User $athlete): ?int
    {
        try {
            return (int) $this->cache->get($this->generationKey($athlete), 0);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function generationKey(User $athlete): string
    {
        return "heatmap:grid:generation:{$athlete->id}";
    }

    /**
     * The grid changes at the calendar-day rollover, not only when a sync runs.
     * Keying on the window alone would let yesterday's cached grid survive
     * midnight and render today as a future cell — stale data presented as
     * current, the one failure mode the PRD names as fatal. Including $today
     * in the key is what makes the cache self-invalidate at midnight.
     */
    private function key(User $athlete, HeatmapWindow $window, CarbonInterface $today, int $generation): string
    {
        $today = CarbonImmutable::instance($today)->toDateString();

        /*
         * The "v4" segment is the format of the key and the shape of the cached object.
         * A grid cached before the weekly trend existed has no `trend` property (and one
         * cached before the day detail had DayCell objects with no `runs`), and
         * unserialize() restores a readonly property it never saw as uninitialized — the
         * entry reads back as a HeatmapGrid and then fails on first access, which the
         * instanceof check in remember() cannot catch. Bump this segment whenever a
         * cached class gains a property or the key changes; old entries are simply never
         * read again and expire on their own.
         *
         * The "g" segment is the athlete's generation: one write bumps it and every
         * window of that athlete gets a new key, on a store that cannot delete by prefix.
         */
        return "heatmap:grid:v4:{$athlete->id}:g{$generation}:{$window->startsOn->toDateString()}:{$today}";
    }
}
