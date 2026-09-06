<?php

namespace App\Enums;

/**
 * The three treatments a heatmap day cell can render.
 *
 * Rest and Future are kept as distinct cases rather than a "ran" boolean plus a
 * "future" boolean: that pairing would let a future rest day exist, which is not
 * a thing this grid can ever show — a day is either in the past (rested or ran)
 * or it has not happened yet.
 */
enum DayCellState: string
{
    case Rest = 'rest';
    case Ran = 'ran';
    case Future = 'future';
}
