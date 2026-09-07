@props(['grid'])

@php
    $weekdays = [
        ['abbr' => 'Mo', 'title' => 'Monday'],
        ['abbr' => 'Tu', 'title' => 'Tuesday'],
        ['abbr' => 'We', 'title' => 'Wednesday'],
        ['abbr' => 'Th', 'title' => 'Thursday'],
        ['abbr' => 'Fr', 'title' => 'Friday'],
        ['abbr' => 'Sa', 'title' => 'Saturday'],
        ['abbr' => 'Su', 'title' => 'Sunday'],
    ];
@endphp

{{-- Markup only: every number and every string comes from a HeatmapGrid/WeekRow/DayCell method — no arithmetic here. --}}
<table class="hm">
    <caption class="sr-only">
        Sixteen rows, one per week, oldest first. Seven columns, Monday to Sunday, plus a weekly total column.
    </caption>
    <colgroup>
        <col class="hm-col-week">
        <col span="7" class="hm-col-day">
        <col class="hm-col-total">
    </colgroup>
    <thead>
        <tr>
            <td class="hm-th-week"><span class="sr-only">Week starting</span></td>
            @foreach ($weekdays as $weekday)
                <th scope="col" class="hm-th-day"><abbr title="{{ $weekday['title'] }}">{{ $weekday['abbr'] }}</abbr></th>
            @endforeach
            <th scope="col" class="hm-th-total">Week</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($grid->weeks as $week)
            <tr class="hm-row @if ($week->isCurrent) hm-row-current @endif">
                <th scope="row" class="hm-th-week"><span class="sr-only">Week of </span>{{ $week->weekLabel() }}</th>
                @foreach ($week->days as $day)
                    <td class="hm-td">
                        <span class="sr-only">{{ $day->accessibleName() }}</span>
                        <span aria-hidden="true" class="hm-cell hm-cell--{{ $day->modifier() }}"
                            @if ($day->pointerTitle() !== null) title="{{ $day->pointerTitle() }}" @endif></span>
                    </td>
                @endforeach
                <td class="hm-total">
                    <span class="hm-total-num">{{ $week->kilometres() }}</span><span class="hm-total-unit"> km</span>
                    @if ($week->isPartial)
                        <span class="hm-partial">partial</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
