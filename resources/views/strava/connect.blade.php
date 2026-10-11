@extends('layouts.app')

@section('title', 'Connect Strava — Sport Heatmap')

@section('content')
    <x-session-notices class="mx-auto mb-4 max-w-sm" :reconnect="$reconnect ?? false" />

    <div class="card mx-auto max-w-sm p-6 text-center">
        <svg width="36" height="36" viewBox="0 0 32 32" aria-hidden="true" class="mx-auto">
            <rect x="3" y="17" width="12" height="12" rx="3" fill="#86b6ef"/>
            <rect x="17" y="17" width="12" height="12" rx="3" fill="#2a78d6"/>
            <rect x="3" y="3" width="12" height="12" rx="3" fill="#3987e5"/>
            <rect x="17" y="3" width="12" height="12" rx="3" fill="#104281"/>
        </svg>

        <h1 class="mt-3 text-base font-semibold">See your training at a glance</h1>

        <p class="mt-1.5 text-sm" style="color: var(--ink-muted);">
            Connect Strava to build your 16-week heatmap. Read-only access to your activities —
            nothing is ever posted on your behalf.
        </p>

        {{--
            Strava orange appears on this control and nowhere else in the product:
            their brand guidelines expect it on the connect button, and it is never
            used as a data colour.
        --}}
        <a href="{{ route('strava.redirect') }}"
           class="mt-4 inline-flex h-11 w-full items-center justify-center rounded-lg px-4 text-sm font-semibold text-white"
           style="background: #fc4c02;">Connect with Strava</a>

        <p class="mt-3 text-xs" style="color: var(--ink-muted);">
            Requests the <code>activity:read_all</code> scope.
        </p>
    </div>
@endsection
