<?php

namespace App\Enums;

/**
 * How a run's climb treats it in the aerobic-efficiency measure (FR-023).
 *
 * Flat runs (under 5 m of climb per km) are the line's evidence. Hilly runs (5 to 10 m per
 * km) are converted to a flat equivalent and join the line only when too few flat runs are
 * left. Steep runs (over 10 m per km) are drawn, converted the same way, and never move
 * the line.
 */
enum EfficiencyRunKind: string
{
    case Flat = 'flat';
    case Hilly = 'hilly';
    case Steep = 'steep';
}
