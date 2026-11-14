@props(['status'])

{{--
    The failed-sync banner of mockups/heatmap-dashboard/states.html §3, without the
    Strava "Reconnect" button: same structure and role="alert". Shown while the newest
    finished sync attempt failed, never otherwise, and the grid below still renders
    what it has. Markup only — every word comes from ImportStatus / GarminSyncFailure.
--}}
@if ($status->syncWarning() !== null)
    <div role="alert" {{ $attributes->class('flex gap-2.5 rounded-lg border px-3 py-2.5 text-sm') }}
         style="background: var(--warn-bg); border-color: var(--warn-border); color: var(--warn-ink);">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" class="mt-0.5 shrink-0">
            <path d="M12 9v4M12 17h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
        </svg>
        <div>
            <p class="font-semibold">{{ $status->syncWarning() }}</p>
            <p class="mt-0.5">{{ $status->syncWarningDetail() }}</p>
        </div>
    </div>
@endif
