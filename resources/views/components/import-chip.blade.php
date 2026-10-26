@props(['status'])

{{-- Markup only: every word comes from ImportStatus — no date arithmetic here. --}}
<span class="import-chip">
    <span aria-hidden="true" class="import-dot"></span>
    {{ $status->chipLabel() }}
</span>
