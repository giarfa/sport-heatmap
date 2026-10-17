@extends('layouts.app')

@section('title', $navigation->pageTitle())

@section('header-status')
    <x-sync-chip :status="$status" />
@endsection

@section('footer-status')· {{ $status->footerLabel() }}@endsection

@section('content')
    <x-session-notices class="mb-4" />

    <h1 class="text-lg font-semibold tracking-tight sm:text-xl">{{ $navigation->heading() }}</h1>
    <p class="mt-0.5 text-sm" style="color: var(--ink-muted);">{{ $grid->label() }} · running distance per day</p>

    <x-window-nav :navigation="$navigation" />

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
                @if ($emptyNote = $navigation->emptyWindowNote($grid, $status))
                    <p class="mb-3 text-sm" style="color: var(--ink-muted);">
                        {{ $emptyNote }}
                    </p>
                @endif
                <x-heatmap-grid :grid="$grid" />
            </section>
            <x-day-detail :grid="$grid" />
        @endif
        <aside class="rail" aria-label="Legend, weekly trend and sync detail">
            <div class="card p-4">
                <x-heatmap-legend :scale="$grid->scale" />
            </div>
            @if ($status->showsSkeleton())
                <x-weekly-trend-skeleton />
            @else
                <x-weekly-trend :trend="$grid->trend" />
            @endif
            <div class="card p-4">
                <x-sync-detail :status="$status" />
            </div>
        </aside>
    </div>

    {{--
        The ways in are one text line under the map, not header buttons: it has
        to stay discreet on a page whose job is to show the map, the 375 px
        header has no width left for a second control, and Strava orange is
        reserved for the connect screen's button. Every visitor can import a
        Garmin CSV (US-011); a guest also gets the Strava sign-in, a signed-in
        owner — already connected — does not.
    --}}
    <p class="mt-6 text-xs" style="color: var(--ink-muted);">
        @guest
            Owner?
            <a href="{{ route('strava.redirect') }}"
               class="inline-flex min-h-11 items-center underline underline-offset-2"
               style="color: var(--accent);">Connect with Strava</a>
            <span aria-hidden="true">·</span>
        @else
            Runs missing from Strava?
        @endguest
        <a href="{{ route('garmin.import.create') }}"
           class="inline-flex min-h-11 items-center underline underline-offset-2"
           style="color: var(--accent);">Import Garmin CSV</a>
    </p>
@endsection
