@props(['trend'])

{{--
    Markup only: every number and string comes from WeeklyTrend / TrendBar / TrendTick —
    no arithmetic here. The y labels, x labels and partial tag are HTML positioned in
    percent beside the SVG, because text and circles inside a viewBox stretched with
    preserveAspectRatio="none" would distort; the SVG itself holds only bars, lines and
    their accessible names.
--}}
<section class="card p-4 trend" aria-labelledby="trend-heading">
    <h3 id="trend-heading" class="trend-title">Weekly distance</h3>
    @if ($trend->isEmpty())
        <p class="trend-empty">No runs in this window, so there is nothing to chart.</p>
    @else
        <p class="trend-note">{{ $trend->noteBefore }}@if ($trend->noteStrong !== '')<strong>{{ $trend->noteStrong }}</strong>@endif{{ $trend->noteAfter }}</p>
        <div class="trend-chart">
            <div class="trend-y" aria-hidden="true">
                @foreach ($trend->ticks as $tick)
                    <span style="bottom: {{ $tick->bottomPercent }}%">{{ $tick->label }}</span>
                @endforeach
            </div>
            <div class="trend-plot">
                <svg viewBox="0 0 160 100" preserveAspectRatio="none" role="group" aria-labelledby="trend-svg-title">
                    <title id="trend-svg-title">{{ $trend->title }}</title>
                    <g aria-hidden="true">
                        @foreach ($trend->ticks as $tick)
                            @continue($loop->first)
                            <line class="trend-gridline" x1="0" x2="160" y1="{{ $tick->y }}" y2="{{ $tick->y }}"/>
                        @endforeach
                        <line class="trend-baseline" x1="0" x2="160" y1="100" y2="100"/>
                    </g>
                    @foreach ($trend->bars as $bar)
                        <g role="img">
                            <title>{{ $bar->accessibleName }}</title>
                            @if ($bar->isZero)
                                <line class="trend-zero" x1="{{ $bar->x }}" x2="{{ $bar->xEnd }}" y1="100" y2="100"/>
                            @else
                                <rect class="trend-bar{{ $bar->isPartial ? ' trend-bar--partial' : '' }}" x="{{ $bar->x }}" y="{{ $bar->y }}" width="6" height="{{ $bar->height }}"/>
                            @endif
                        </g>
                    @endforeach
                    @if ($trend->hasLine())
                        <g aria-hidden="true">
                            @foreach ($trend->lineSegments as $points)
                                <polyline class="trend-line-halo" points="{{ $points }}"/>
                                <polyline class="trend-line" points="{{ $points }}"/>
                            @endforeach
                        </g>
                    @endif
                </svg>
                @foreach ($trend->bars as $bar)
                    @if ($bar->isPartial)
                        <span class="trend-partial-tag" aria-hidden="true" style="bottom: calc({{ $bar->heightPercent }}% + 2px)">partial</span>
                    @endif
                @endforeach
            </div>
            <div class="trend-x" aria-hidden="true">
                @foreach ($trend->xLabels as $label)
                    <span>{{ $label }}</span>
                @endforeach
            </div>
        </div>
        <ul class="trend-key">
            <li><span aria-hidden="true" class="trend-key-swatch trend-key-swatch--bar"></span>Weekly total</li>
            <li><span aria-hidden="true" class="trend-key-swatch trend-key-swatch--line"></span>4-week average</li>
            @if ($trend->hasPartial)
                <li><span aria-hidden="true" class="trend-key-swatch trend-key-swatch--partial"></span>Partial week</li>
            @endif
            @if ($trend->hasZeroWeek)
                <li><span aria-hidden="true" class="trend-key-swatch trend-key-swatch--zero"></span>0 km week</li>
            @endif
        </ul>
        <details class="trend-figures">
            <summary>Weekly figures</summary>
            <table class="trend-table">
                <caption class="sr-only">{{ $trend->captionLabel }}</caption>
                <thead>
                    <tr>
                        <th scope="col">Week</th>
                        <th scope="col">Total</th>
                        <th scope="col">4-wk avg</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($trend->bars as $bar)
                        <tr>
                            <th scope="row">{{ $bar->rangeLabel }}@if ($bar->isPartial) <span class="trend-table-partial">partial</span>@endif</th>
                            <td>{{ $bar->kilometres }} km</td>
                            <td>@if ($bar->averageKilometres !== null){{ $bar->averageKilometres }} km@else<span aria-hidden="true">—</span><span class="sr-only">none</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </details>
    @endif
</section>
