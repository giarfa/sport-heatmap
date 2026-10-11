@extends('layouts.app')

@section('title', 'Dashboard — Sport Heatmap')

@section('header-status')
    <x-sync-chip :status="$status" />
@endsection

@section('footer-status')· {{ $status->footerLabel() }}@endsection

@section('content')
    <x-session-notices class="mb-4" />

    <h1 class="text-lg font-semibold tracking-tight sm:text-xl">Last 16 weeks</h1>
    <p class="mt-0.5 text-sm" style="color: var(--ink-muted);">{{ $grid->label() }} · running distance per day</p>

    @if ($status->needsWarning())
        <div class="mt-4">
            <x-sync-warning :status="$status" />
        </div>
    @endif

    <div class="dash mt-4">
        @if ($status->showsSkeleton())
            <x-heatmap-skeleton />
        @else
            <section class="card dash-grid p-3 sm:p-5" aria-labelledby="grid-heading">
                <h2 id="grid-heading" class="sr-only">Daily running distance, 16 weeks by weekday</h2>
                @if ($status->runsInWindow === 0)
                    <p class="mb-3 text-sm" style="color: var(--ink-muted);">
                        No runs found in the last 16 weeks. The map fills in after your next activity syncs.
                    </p>
                @endif
                <x-heatmap-grid :grid="$grid" />
            </section>
        @endif
        <aside class="rail" aria-label="Legend and sync detail">
            <div class="card p-4">
                <x-heatmap-legend :scale="$grid->scale" />
            </div>
            <div class="card p-4">
                <x-sync-detail :status="$status" />
            </div>
        </aside>
    </div>
@endsection
