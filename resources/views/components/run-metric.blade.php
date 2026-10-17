@props(['value'])

{{--
    One <dd> of a run's pace, ascent or heart rate. A metric that was not recorded is
    an em dash read aloud as "not recorded", never a 0 — the value arrives already
    formatted from GarminImportFormat, or null.
--}}
@if ($value === null)
    <dd class="is-empty"><span aria-hidden="true">—</span><span class="sr-only">not recorded</span></dd>
@else
    <dd>{{ $value }}</dd>
@endif
