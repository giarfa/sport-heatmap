@extends('layouts.app')

@section('title', 'Import Garmin CSV — Sport Heatmap')

@section('content')
    <a href="{{ route('dashboard') }}" class="back-link">← Back to heatmap</a>

    <div class="imp">
        <x-session-notices class="mb-4" />

        <h1 class="text-lg font-semibold tracking-tight sm:text-xl">Import Garmin CSV</h1>
        <p class="mt-0.5 text-sm" style="color: var(--ink-muted);">Add runs from Garmin Connect. You review every clash before anything is saved.</p>

        @php($csvError = $errors->first('csv'))

        <form method="POST" action="{{ route('garmin.import.store') }}" enctype="multipart/form-data" class="card mt-4 px-5 pt-4 pb-5">
            @csrf
            <label for="csv" class="field-label">Activities export (.csv)</label>
            <p id="csv-hint" class="field-hint">Garmin Connect → Activities → Export CSV · Italian or English · metric units · max 5 MB</p>
            <input id="csv" name="csv" type="file" accept=".csv,text/csv" required class="file-input"
                   aria-describedby="csv-hint{{ $csvError ? ' csv-error' : '' }}"
                   @if ($csvError) aria-invalid="true" @endif>
            @if ($csvError)
                <p id="csv-error" class="field-error">{{ $csvError }}</p>
            @endif
            <div class="btn-row">
                <button type="submit" class="btn btn--primary">Review import</button>
            </div>
        </form>
    </div>
@endsection
