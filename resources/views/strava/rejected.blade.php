@extends('layouts.app')

@section('title', 'Not connected — Sport Heatmap')

@section('content')
    <div class="card mx-auto max-w-sm p-6 text-center">
        <div class="mx-auto grid h-10 w-10 place-items-center rounded-full"
             style="background: var(--warn-bg); color: var(--warn-ink);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 aria-hidden="true">
                <circle cx="12" cy="12" r="9"/>
                <path d="M15 9l-6 6M9 9l6 6"/>
            </svg>
        </div>

        @if (($reason ?? 'allowlist') === 'scope')
            <h1 class="mt-3 text-base font-semibold">Read access is required</h1>
            <p class="mt-1.5 text-sm" style="color: var(--ink-muted);">
                Sport Heatmap needs the
                <code style="color: var(--ink);">{{ $requiredScope ?? 'activity:read_all' }}</code>
                permission to read your activities. It was not granted, so no session was created
                and nothing was stored.
            </p>
        @elseif (($reason ?? 'allowlist') === 'write_scope')
            <h1 class="mt-3 text-base font-semibold">Only read access is accepted</h1>
            <p class="mt-1.5 text-sm" style="color: var(--ink-muted);">
                Sport Heatmap never writes to Strava, so it will not hold a permission that lets it.
                Authorize again granting only
                <code style="color: var(--ink);">{{ $requiredScope ?? 'activity:read_all' }}</code> —
                no session was created and nothing was stored.
            </p>
        @elseif (($reason ?? 'allowlist') === 'exchange')
            <h1 class="mt-3 text-base font-semibold">Strava could not be reached</h1>
            <p class="mt-1.5 text-sm" style="color: var(--ink-muted);">
                Strava did not complete the authorization. Nothing was created and nothing was
                stored — try again in a moment.
            </p>
        @elseif (($reason ?? 'allowlist') === 'taken')
            <h1 class="mt-3 text-base font-semibold">That Strava account is already in use</h1>
            <p class="mt-1.5 text-sm" style="color: var(--ink-muted);">
                This Strava athlete is already attached to another account on this instance,
                so nothing was stored.
            </p>
        @else
            <h1 class="mt-3 text-base font-semibold">This instance is private</h1>
            <p class="mt-1.5 text-sm" style="color: var(--ink-muted);">
                Athlete <span class="font-medium" style="color: var(--ink);">#{{ $athleteId ?? 'unknown' }}</span>
                is not on this instance's allowlist, so no session was created and nothing was stored.
            </p>
        @endif

        <a href="{{ route('dashboard') }}"
           class="mt-4 inline-flex h-11 items-center justify-center rounded-lg border px-4 text-sm font-medium"
           style="border-color: var(--hairline); color: var(--ink);">Back</a>
    </div>
@endsection
