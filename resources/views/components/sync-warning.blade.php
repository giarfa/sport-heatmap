@props(['status'])

{{--
    Markup only: title, reason and the recovery label all come from
    SyncStatus. warningReason() can carry a raw Strava error string, so it is
    escaped rather than passed through {!! !!}.
--}}
<div role="alert" class="sync-alert">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
         aria-hidden="true" class="mt-0.5 shrink-0">
        <path d="M12 9v4M12 17h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
    </svg>
    <div>
        <p class="sync-alert-title">{{ $status->warningTitle() }}</p>
        <p class="mt-0.5">{{ $status->warningReason() }}</p>
        <a href="{{ route('strava.redirect') }}" class="sync-alert-action">{{ $status->recoveryLabel() }}</a>
    </div>
</div>
