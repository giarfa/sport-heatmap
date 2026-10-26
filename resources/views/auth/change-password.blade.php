@extends('layouts.app')

@section('title', 'Set your password — Sport Heatmap')

@section('content')
    <div class="imp mx-auto sm:max-w-sm">
        <h1 class="text-lg font-semibold tracking-tight sm:text-xl">Set your password</h1>
        <p class="mt-0.5 text-sm" style="color: var(--ink-muted);">This instance has no password yet. Choose one with at least 12 characters.</p>

        @php($passwordError = $errors->first('password'))

        <form method="POST" action="{{ route('password.update') }}" class="card mt-4 px-5 pt-4 pb-5">
            @csrf
            @method('PUT')
            <label for="password" class="field-label">New password</label>
            <p id="password-hint" class="field-hint">At least 12 characters.</p>
            <input id="password" name="password" type="password" required autocomplete="new-password" class="text-input"
                   aria-describedby="password-hint{{ $passwordError ? ' password-error' : '' }}"
                   @if ($passwordError) aria-invalid="true" @endif>
            @if ($passwordError)
                <p id="password-error" class="field-error" role="alert">{{ $passwordError }}</p>
            @endif

            <label for="password_confirmation" class="field-label mt-4">Confirm new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="text-input">

            <div class="btn-row">
                <button type="submit" class="btn btn--primary w-full">Save password</button>
            </div>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="mt-3">
            @csrf
            <button type="submit" class="btn btn--secondary w-full">Sign out</button>
        </form>
    </div>
@endsection
