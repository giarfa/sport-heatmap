@props(['scale'])

{{-- Markup only: every string comes from IntensityScale/LegendRow — no arithmetic here. --}}
<h3 class="hm-legend-title">Intensity</h3>
<p class="hm-legend-note">{{ $scale->note() }}</p>
<ul class="hm-legend-list">
    @foreach ($scale->legendRows() as $row)
        <li class="hm-key">
            <span aria-hidden="true" class="hm-cell hm-key-swatch hm-cell--{{ $row->level === 0 ? 'rest' : 'l'.$row->level }}"></span>
            <span class="hm-key-name">{{ $row->name }}</span>
            <span class="hm-key-range">{{ $row->rangeLabel }}</span>
        </li>
    @endforeach
</ul>
