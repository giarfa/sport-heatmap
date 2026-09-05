@extends('layouts.app')

@section('title', 'Dashboard — Sport Heatmap')

@section('content')
    <h1 class="text-lg font-semibold tracking-tight sm:text-xl">Your training</h1>

    {{-- The heatmap grid, the legend and the sync status land in US-004 through US-006. --}}
    <div class="card mt-4 p-6">
        <p class="text-sm" style="color: var(--ink-muted);">
            Strava is connected. The heatmap arrives with the next spec.
        </p>
    </div>
@endsection
