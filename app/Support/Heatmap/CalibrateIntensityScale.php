<?php

namespace App\Support\Heatmap;

use App\Data\Heatmap\IntensityScale;

/**
 * The only place percentile arithmetic and the two fallbacks live: takes the
 * window's daily metre totals and returns the IntensityScale everything else
 * buckets against.
 */
final readonly class CalibrateIntensityScale
{
    /**
     * @param  array<string, int>  $dailyMetres  keyed by "Y-m-d" => summed distance in metres
     */
    public function __invoke(array $dailyMetres): IntensityScale
    {
        $nonZero = array_values(array_filter($dailyMetres, fn (int $metres): bool => $metres > 0));
        sort($nonZero);

        $count = count($nonZero);

        if ($count < 4) {
            return IntensityScale::fixedBands($count, degenerate: false);
        }

        /*
         * R-7 linear interpolation (the numpy / Excel PERCENTILE.INC default)
         * is the method that reproduces the mockup's legend — 7.2 / 9.9 /
         * 14.1 km over its 68-day dataset. Nearest-rank yields 7.0 / 9.8 /
         * 14.0 km instead, which passes a naive unit test but silently
         * desynchronises the legend from the visual contract.
         */
        $p25 = $this->percentile($nonZero, 0.25);
        $p50 = $this->percentile($nonZero, 0.50);
        $p75 = $this->percentile($nonZero, 0.75);

        /*
         * Checked after computing the percentiles, so it catches every
         * collapse — not only the all-identical-distance case — and exists
         * so a uniform block does not silently render every running day at
         * a single level, which would look like a bug and hide the data.
         */
        if ($p25 === $p75) {
            return IntensityScale::fixedBands($count, degenerate: true);
        }

        return IntensityScale::calibrated($p25, $p50, $p75, $count);
    }

    /**
     * @param  array<int, int>  $sorted
     */
    private function percentile(array $sorted, float $p): int
    {
        $n = count($sorted);
        $h = ($n - 1) * $p;
        $lower = (int) floor($h);
        $upper = (int) ceil($h);

        $value = $sorted[$lower] + ($h - $lower) * ($sorted[$upper] - $sorted[$lower]);

        return (int) round($value);
    }
}
