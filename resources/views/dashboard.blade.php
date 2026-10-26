@extends('layouts.app')

@section('title', $navigation->pageTitle())

@section('header-status')
    <x-import-chip :status="$status" />
@endsection

@section('footer-status')· {{ $status->footerLabel() }}@endsection

@section('content')
    <x-session-notices class="mb-4" />

    <h1 class="text-lg font-semibold tracking-tight sm:text-xl">{{ $navigation->heading() }}</h1>
    <p class="mt-0.5 text-sm" style="color: var(--ink-muted);">{{ $grid->label() }} · running distance per day</p>

    <x-window-nav :navigation="$navigation" />

    <div class="dash mt-4">
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
        <aside class="rail" aria-label="Legend, weekly trend and import detail">
            <div class="card p-4">
                <x-heatmap-legend :scale="$grid->scale" />
            </div>
            <x-weekly-trend :trend="$grid->trend" />
            <div class="card p-4">
                <x-import-detail :status="$status" />
            </div>
        </aside>
    </div>

    {{--
        The ways out of the map are one text line under it, not header buttons: it
        has to stay discreet on a page whose job is to show the map, and the
        375 px header has no width left for a second control. The owner is always
        signed in here (the route sits behind `auth`).
    --}}
    <p class="mt-6 text-xs" style="color: var(--ink-muted);">
        <a href="{{ route('garmin.import.create') }}"
           class="inline-flex min-h-11 items-center underline underline-offset-2"
           style="color: var(--accent);">Import Garmin CSV</a>
        <span aria-hidden="true">·</span>
        <form method="POST" action="{{ route('logout') }}" class="inline">
            @csrf
            <button type="submit"
                    class="inline-flex min-h-11 items-center underline underline-offset-2"
                    style="color: var(--accent);">Sign out</button>
        </form>
    </p>
@endsection
