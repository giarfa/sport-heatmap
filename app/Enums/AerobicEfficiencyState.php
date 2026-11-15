<?php

namespace App\Enums;

/**
 * What the aerobic-efficiency card shows for a window (FR-023).
 *
 * Kept as four cases rather than flags: a window cannot be both empty and measured, and
 * the card renders exactly one of a sentence, a sentence over a chart, or a headline over
 * a chart.
 */
enum AerobicEfficiencyState: string
{
    /** No stored run in the window: the Weekly distance sentence. */
    case Empty = 'empty';

    /** Fewer than 8 qualifying runs: the count is stated, nothing is charted. */
    case NotEnoughRuns = 'not-enough-runs';

    /** Enough runs, but no 4 weeks hold 3 usable ones: dots and table without a median line. */
    case NoMedian = 'no-median';

    /** A headline, a note, the dots, the median line and the weekly figures. */
    case Measured = 'measured';
}
