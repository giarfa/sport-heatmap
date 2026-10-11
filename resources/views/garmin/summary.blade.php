@extends('layouts.app')

@section('title', 'Import complete — Sport Heatmap')

@php
    $added = $summary->imported;
    $lede = $added + $summary->overwritten === 0 && $summary->alreadyImported > 0
        ? 'Nothing new — every run was already imported.'
        : ($added === 1 ? '1 run added' : "{$added} runs added").", {$summary->overwritten} overwritten. The heatmap is up to date.";
@endphp

@section('content')
    <div class="imp">
        <h1 class="text-lg font-semibold tracking-tight sm:text-xl">Import complete</h1>
        <p class="mt-0.5 text-sm" style="color: var(--ink-muted);" role="status">{{ $lede }}</p>

        <section class="card mt-4 px-5 py-4" aria-labelledby="result-heading">
            <h2 id="result-heading" class="section-title">Result</h2>
            <dl class="tally mt-3">
                <div><dt>Imported</dt><dd>{{ $summary->imported }}</dd></div>
                <div><dt>Overwritten</dt><dd>{{ $summary->overwritten }}</dd></div>
                <div><dt>Skipped</dt><dd>{{ $summary->skipped }}</dd></div>
                <div><dt>Already imported</dt><dd>{{ $summary->alreadyImported }}</dd></div>
                <div><dt>Not runs, ignored</dt><dd>{{ $summary->ignoredTotal() }}</dd></div>
                <div><dt>Outside 16 weeks</dt><dd>{{ $summary->outsideWindow }}</dd></div>
                <div><dt>Unreadable rows</dt><dd>{{ count($summary->invalid) }}</dd></div>
            </dl>
        </section>

        @if ($summary->ignoredByType !== [])
            <section class="card mt-4 px-5 py-4" aria-labelledby="ignored-heading">
                <h2 id="ignored-heading" class="section-title">Ignored activity types</h2>
                <p class="section-note">Only runs go on the heatmap. If one of these is a run, tell us its name.</p>
                <ul class="list-plain">
                    @foreach ($summary->ignoredByType as $type => $count)
                        <li><span>{{ $type }}</span><span class="muted">{{ $count }}</span></li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($summary->invalid !== [])
            <section class="card mt-4 px-5 py-4" aria-labelledby="invalid-heading">
                <h2 id="invalid-heading" class="section-title">Unreadable rows</h2>
                <p class="section-note">Skipped. The rest of the file was imported.</p>
                <ul class="list-plain">
                    @foreach ($summary->invalid as $invalidRow)
                        <li><span>Line {{ $invalidRow['line'] }}</span><span class="muted">{{ $invalidRow['reason'] }}</span></li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="btn-row">
            <a href="{{ route('dashboard') }}" class="btn btn--primary">Back to heatmap</a>
        </div>
    </div>
@endsection
