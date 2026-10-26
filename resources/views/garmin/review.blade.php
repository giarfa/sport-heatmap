@extends('layouts.app')

@section('title', 'Review Garmin import — Sport Heatmap')

@use('App\Support\Garmin\GarminImportFormat')

@section('content')
    <div class="imp imp--wide">
        <h1 class="text-lg font-semibold tracking-tight sm:text-xl">Review import</h1>
        <p class="mt-0.5 text-sm" style="color: var(--ink-muted);">Nothing is saved until you confirm.</p>

        @if (session('garmin_import.stale') || $errors->has('resolutions') || $errors->has('token') || $errors->has('fingerprint'))
            <div role="alert" class="notice mt-4">
                {{ session('garmin_import.stale') ?? $errors->first('resolutions') ?: 'This review expired — check the conflicts again.' }}
            </div>
        @endif

        <section class="card mt-4 px-5 py-4" aria-labelledby="tally-heading">
            <h2 id="tally-heading" class="sr-only">What the file contains</h2>
            <dl class="tally">
                <div><dt>New runs</dt><dd>{{ count($plan->newRows) }}</dd></div>
                <div><dt>Clashes to decide</dt><dd>{{ count($plan->conflicts) }}</dd></div>
                <div><dt>Already imported</dt><dd>{{ count($plan->alreadyImported) }}</dd></div>
                <div><dt>Not runs, ignored</dt><dd>{{ $parsed->ignoredTotal() }}</dd></div>
                <div><dt>Future-dated</dt><dd>{{ $parsed->futureDated }}</dd></div>
                <div><dt>Unreadable rows</dt><dd>{{ count($parsed->invalid) }}</dd></div>
            </dl>
        </section>

        <form method="POST" action="{{ route('garmin.import.confirm') }}" class="mt-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="fingerprint" value="{{ $plan->fingerprint() }}">

            @if ($plan->conflicts !== [])
                <section class="card px-5 py-4" aria-labelledby="clashes-heading">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 id="clashes-heading" class="section-title">Clashes</h2>
                            <p class="section-note">A stored run starts within 2 minutes of a run in the file. Skip keeps the stored one. Overwrite replaces the run and its pace, ascent and heart rate; a “—” in the file keeps the stored value.</p>
                        </div>
                        {{-- Revealed by resources/js/app.js; without JS they never appear and the radios still work. --}}
                        <div class="shortcuts" data-resolve-shortcuts hidden>
                            <button type="button" class="btn btn--secondary" data-resolve-all="overwrite">Overwrite all</button>
                            <button type="button" class="btn btn--secondary" data-resolve-all="skip">Skip all</button>
                        </div>
                    </div>

                    <table class="cmp mt-3">
                        <caption class="sr-only">Stored run compared with the run in the file, and what to keep</caption>
                        <thead>
                            <tr>
                                <th scope="col">Run</th>
                                <th scope="col">Stored</th>
                                <th scope="col">Garmin file</th>
                                <th scope="col">Keep</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($plan->conflicts as $index => $conflict)
                                <tr>
                                    <th scope="row">
                                        <span class="run-date">{{ GarminImportFormat::date($conflict->row->startDateLocal) }}</span>{{ $conflict->row->name }}
                                    </th>
                                    <td data-label="Stored" class="cmp-pair">
                                        <dl class="side">
                                            <div><dt>Start</dt><dd>{{ GarminImportFormat::start($conflict->stored->start_date_local) }}</dd></div>
                                            <div><dt>Distance</dt><dd>{{ GarminImportFormat::distance($conflict->stored->distance_meters) }}</dd></div>
                                            <div><dt>Moving</dt><dd>{{ GarminImportFormat::duration($conflict->stored->moving_time_seconds) }}</dd></div>
                                            <div><dt>Avg pace</dt><x-run-metric :value="GarminImportFormat::pace($conflict->stored->average_pace_seconds_per_km)" /></div>
                                            <div><dt>Ascent</dt><x-run-metric :value="GarminImportFormat::ascent($conflict->stored->total_ascent_meters)" /></div>
                                            <div><dt>Heart rate</dt><x-run-metric :value="GarminImportFormat::heartRate($conflict->stored->average_heart_rate)" /></div>
                                        </dl>
                                        <p class="side-source">
                                            {{ $conflict->stored->name }} ·
                                            @if ($conflict->stored->stravaUrl() !== null)
                                                Strava · <a href="{{ $conflict->stored->stravaUrl() }}">View on Strava</a>
                                            @else
                                                Garmin import
                                            @endif
                                        </p>
                                    </td>
                                    <td data-label="Garmin file" class="cmp-pair">
                                        <dl class="side">
                                            <div><dt>Start</dt><dd>{{ GarminImportFormat::start($conflict->row->startDateLocal) }}</dd></div>
                                            <div><dt>Distance</dt><dd>{{ GarminImportFormat::distance($conflict->row->distanceMeters) }}</dd></div>
                                            <div><dt>Moving</dt><dd>{{ GarminImportFormat::duration($conflict->row->movingTimeSeconds) }}</dd></div>
                                            <div><dt>Avg pace</dt><x-run-metric :value="GarminImportFormat::pace($conflict->row->averagePaceSecondsPerKm)" /></div>
                                            <div><dt>Ascent</dt><x-run-metric :value="GarminImportFormat::ascent($conflict->row->totalAscentMeters)" /></div>
                                            <div><dt>Heart rate</dt><x-run-metric :value="GarminImportFormat::heartRate($conflict->row->averageHeartRate)" /></div>
                                        </dl>
                                    </td>
                                    <td data-label="Keep">
                                        <fieldset class="choice">
                                            <legend>{{ $conflict->row->name }}, {{ $conflict->row->startDateLocal->format('j M H:i') }}</legend>
                                            <div class="seg">
                                                <input type="radio" id="resolution-{{ $index }}-skip" name="resolutions[{{ $index }}]" value="skip" checked>
                                                <label for="resolution-{{ $index }}-skip">Skip</label>
                                                <input type="radio" id="resolution-{{ $index }}-overwrite" name="resolutions[{{ $index }}]" value="overwrite">
                                                <label for="resolution-{{ $index }}-overwrite">Overwrite</label>
                                            </div>
                                        </fieldset>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
            @endif

            <section class="card mt-4 px-5 py-4" aria-labelledby="new-heading">
                <h2 id="new-heading" class="section-title">New runs</h2>
                @if ($plan->newRows === [])
                    <p class="section-note">
                        {{ $plan->conflicts === [] ? 'Nothing new — every run in the file is already stored.' : 'Every run in the file clashes with a stored one.' }}
                    </p>
                @else
                    <p class="section-note">No stored run clashes with these. They are added as they are.</p>
                    <table class="cmp mt-3">
                        <caption class="sr-only">Runs in the file that will be added</caption>
                        <thead>
                            <tr>
                                <th scope="col">Run</th>
                                <th scope="col">Start</th>
                                <th scope="col">Distance</th>
                                <th scope="col">Moving</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($plan->newRows as $row)
                                <tr>
                                    <th scope="row"><span class="run-date">{{ GarminImportFormat::date($row->startDateLocal) }}</span>{{ $row->name }}</th>
                                    <td data-label="Start" class="num">{{ GarminImportFormat::start($row->startDateLocal) }}</td>
                                    <td data-label="Distance" class="num">{{ GarminImportFormat::distance($row->distanceMeters) }}</td>
                                    <td data-label="Moving" class="num">{{ GarminImportFormat::duration($row->movingTimeSeconds) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>

            <div class="btn-row">
                <button type="submit" class="btn btn--primary">Confirm import</button>
                <button type="submit" class="btn btn--secondary" formaction="{{ route('garmin.import.cancel') }}" formnovalidate>Cancel</button>
            </div>
        </form>
    </div>
@endsection
