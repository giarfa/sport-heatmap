@props(['status'])

{{--
    Markup only: every word comes from SyncStatus — no date arithmetic here.
    Redundant whenever a warning banner is also on the page, so a screen
    reader is never told the same fact twice.
--}}
<span class="sync-chip" @if ($status->chipIsRedundant()) aria-hidden="true" @endif>
    <span aria-hidden="true" class="sync-dot sync-dot--ok"></span>
    {{ $status->chipLabel() }}
</span>
