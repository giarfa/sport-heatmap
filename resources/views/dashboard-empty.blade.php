@extends('layouts.app')

@section('title', 'Dashboard — Sport Heatmap')

@section('header-status')
    <x-import-chip :status="$status" />
@endsection

@section('footer-status')· {{ $status->footerLabel() }}@endsection

@section('content')
    <x-session-notices class="mx-auto mb-4 max-w-sm" />

    <div class="card mx-auto max-w-sm p-6 text-center">
        <svg width="36" height="36" viewBox="0 0 32 32" aria-hidden="true" class="mx-auto">
            <rect x="3" y="17" width="12" height="12" rx="3" fill="#86b6ef"/>
            <rect x="17" y="17" width="12" height="12" rx="3" fill="#2a78d6"/>
            <rect x="3" y="3" width="12" height="12" rx="3" fill="#3987e5"/>
            <rect x="17" y="3" width="12" height="12" rx="3" fill="#104281"/>
        </svg>

        <h1 class="mt-3 text-base font-semibold">See your training at a glance</h1>

        <p class="mt-1.5 text-sm" style="color: var(--ink-muted);">
            Import a Garmin Connect CSV export to build your 16-week heatmap.
        </p>

        <a href="{{ route('garmin.import.create') }}" class="btn btn--primary mt-4 w-full">Import Garmin CSV</a>

        {{-- The owner is always signed in here (the route sits behind `auth`). --}}
        <form method="POST" action="{{ route('logout') }}" class="mt-3">
            @csrf
            <button type="submit" class="btn btn--secondary w-full">Sign out</button>
        </form>
    </div>
@endsection
