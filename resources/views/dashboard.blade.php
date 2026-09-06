@extends('layouts.app')

@section('title', 'Dashboard — Sport Heatmap')

@section('content')
    <h1 class="text-lg font-semibold tracking-tight sm:text-xl">Last 16 weeks</h1>
    <p class="mt-0.5 text-sm" style="color: var(--ink-muted);">{{ $grid->label() }} · running distance per day</p>

    <div class="dash mt-4">
        <section class="card dash-grid p-3 sm:p-5" aria-labelledby="grid-heading">
            <h2 id="grid-heading" class="sr-only">Daily running distance, 16 weeks by weekday</h2>
            <x-heatmap-grid :grid="$grid" />
        </section>
        <aside class="rail" aria-label="Legend and sync detail">
            {{-- The intensity legend has landed; the sync detail card is still US-006. --}}
            <div class="card p-4">
                <x-heatmap-legend :scale="$grid->scale" />
            </div>
            <div class="card p-4">
                <p class="text-sm" style="color: var(--ink-muted);">Sync detail arrives with the next spec.</p>
            </div>
        </aside>
    </div>
@endsection
