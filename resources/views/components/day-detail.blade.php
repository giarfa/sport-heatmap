@props(['grid'])

{{--
    The detail a ran day's button opens (FR-011), mirroring .larapilot/mockups/US-012/day-detail.html.

    One shared <dialog> and one inert <template> per ran day, filled by resources/js/app.js:
    the runs are part of the cached grid, so opening a day makes no request, and a sync or an import that forgets
    the grid forgets the detail with it. Every string comes from DayCell / DayRun; a missing
    metric is an em dash read aloud as "not recorded", never a 0. Run names are Strava's or
    Garmin's text, so they are escaped.
--}}
<dialog class="day-dlg" id="day-dialog" aria-labelledby="dlg-title">
    <div class="dlg-head">
        <div data-dlg-title></div>
        <button type="button" class="dlg-close" data-dlg-close>
            <span class="sr-only">Close</span>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <path d="M6 6l12 12M18 6L6 18"/>
            </svg>
        </button>
    </div>
    <div class="dlg-body" data-dlg-body></div>
</dialog>

@foreach ($grid->daysWithDetail() as $day)
    <template id="day-{{ $day->date->toDateString() }}">
        <h2 id="dlg-title">{{ $day->detailTitle() }}</h2>
        <p class="dlg-sub">{{ $day->detailSummary() }}</p>
        <ol class="runs">
            @foreach ($day->runs as $run)
                <li class="run">
                    <h3 class="run-name">{{ $run->name }}</h3>
                    <p class="run-src">
                        {{ $run->sourceLabel() }}
                        @if ($run->stravaUrl !== null)
                            · <a href="{{ $run->stravaUrl }}">View on Strava<span class="sr-only"> — {{ $run->name }}</span></a>
                        @endif
                    </p>
                    <dl class="run-stats">
                        <div><dt>Distance</dt><dd>{{ $run->kilometres() }}</dd></div>
                        <div><dt>Moving time</dt><dd>{{ $run->movingTime() }}</dd></div>
                        <div><dt>Avg pace</dt><x-run-metric :value="$run->pace()" /></div>
                        <div><dt>Ascent</dt><x-run-metric :value="$run->ascent()" /></div>
                        <div><dt>Heart rate</dt><x-run-metric :value="$run->heartRate()" /></div>
                    </dl>
                </li>
            @endforeach
        </ol>
    </template>
@endforeach
