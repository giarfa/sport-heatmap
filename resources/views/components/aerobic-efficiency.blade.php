@props(['efficiency'])

{{--
    Markup only: every number and string comes from AerobicEfficiency / EfficiencyDot /
    EfficiencyWeek / TrendTick — no arithmetic here. The twin of <x-weekly-trend>: it reuses
    that card's frame (trend-*) and adds the eff-* rules. Run dots, y labels and x labels are
    HTML positioned in percent beside the SVG, because circles and text inside a viewBox
    stretched with preserveAspectRatio="none" would distort.
--}}
<section class="card p-4 trend eff" aria-labelledby="eff-heading">
    <h3 id="eff-heading" class="trend-title">Aerobic efficiency</h3>
    @if (! $efficiency->hasChart())
        <p class="trend-empty">{{ $efficiency->message }}</p>
    @else
        @if ($efficiency->hasHeadline())
            <p class="eff-headline"><strong>{{ $efficiency->headlinePace }}</strong><span class="eff-unit"> /km</span> <span class="eff-at">{{ $efficiency->headlineAt }}</span></p>
        @else
            <p class="trend-note">{{ $efficiency->message }}</p>
        @endif
        <p class="trend-note">{{ $efficiency->note }}</p>
        <div class="trend-chart">
            <div class="trend-y" aria-hidden="true">
                @foreach ($efficiency->ticks as $tick)
                    <span style="bottom: {{ $tick->bottomPercent }}%">{{ $tick->label }}</span>
                @endforeach
            </div>
            <div class="trend-plot">
                <svg viewBox="0 0 160 100" preserveAspectRatio="none" role="img" aria-labelledby="eff-svg-title">
                    <title id="eff-svg-title">{{ $efficiency->title }}</title>
                    <g aria-hidden="true">
                        @foreach ($efficiency->ticks as $tick)
                            @continue($loop->first || $loop->last)
                            <line class="trend-gridline" x1="0" x2="160" y1="{{ $tick->y }}" y2="{{ $tick->y }}"/>
                        @endforeach
                        <line class="trend-baseline" x1="0" x2="160" y1="100" y2="100"/>
                        @foreach ($efficiency->lastYearSegments as $points)
                            <polyline class="eff-lastyear" points="{{ $points }}"/>
                        @endforeach
                        @foreach ($efficiency->medianSegments as $points)
                            <polyline class="trend-line-halo" points="{{ $points }}"/>
                            <polyline class="trend-line" points="{{ $points }}"/>
                        @endforeach
                    </g>
                </svg>
                @foreach ($efficiency->dots as $dot)
                    <span class="eff-dot eff-dot--{{ $dot->kind->value }}" aria-hidden="true" style="left: {{ $dot->left }}%; top: {{ $dot->top }}%"></span>
                @endforeach
            </div>
            <div class="trend-x" aria-hidden="true">
                @foreach ($efficiency->xLabels as $label)
                    <span>{{ $label }}</span>
                @endforeach
            </div>
        </div>
        <ul class="trend-key">
            <li><span aria-hidden="true" class="eff-key-dot eff-dot--flat"></span>Run</li>
            @if ($efficiency->hasHilly)
                <li><span aria-hidden="true" class="eff-key-dot eff-dot--hilly"></span>Hilly run, adjusted</li>
            @endif
            @if ($efficiency->hasSteep)
                <li><span aria-hidden="true" class="eff-key-dot eff-dot--steep"></span>Steep run, not counted</li>
            @endif
            @if ($efficiency->hasMedianLine())
                <li><span aria-hidden="true" class="trend-key-swatch trend-key-swatch--line"></span>4-week median</li>
            @endif
            @if ($efficiency->hasLastYear)
                <li><span aria-hidden="true" class="eff-key-lastyear"></span>Same weeks last year</li>
            @endif
        </ul>
        <details class="trend-figures">
            <summary>Weekly figures</summary>
            <table class="trend-table">
                <caption class="sr-only">{{ $efficiency->captionLabel }}</caption>
                <thead>
                    <tr>
                        <th scope="col">Week</th>
                        <th scope="col">Runs</th>
                        <th scope="col">4-wk median</th>
                        @if ($efficiency->hasLastYear)
                            <th scope="col">Last year</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($efficiency->weeks as $week)
                        <tr>
                            <th scope="row">{{ $week->rangeLabel }}</th>
                            <td>{{ $week->runs }}</td>
                            <td>@if ($week->median !== null){{ $week->median }}@else<span aria-hidden="true">—</span><span class="sr-only">none</span>@endif</td>
                            @if ($efficiency->hasLastYear)
                                <td>@if ($week->lastYear !== null){{ $week->lastYear }}@else<span aria-hidden="true">—</span><span class="sr-only">none</span>@endif</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </details>
    @endif
</section>
