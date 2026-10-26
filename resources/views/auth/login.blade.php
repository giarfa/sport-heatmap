@extends('layouts.app')

@section('title', 'Sign in — Sport Heatmap')

@section('content')
    <div class="imp mx-auto sm:max-w-sm">
        <h1 class="text-lg font-semibold tracking-tight sm:text-xl">Sign in</h1>
        <p class="mt-0.5 text-sm" style="color: var(--ink-muted);">This heatmap is private.</p>

        @php($usernameError = $errors->first('username'))
        @php($passwordError = $errors->first('password'))

        <form method="POST" action="{{ route('login.store') }}" class="card mt-4 px-5 pt-4 pb-5">
            @csrf
            <label for="username" class="field-label">Username</label>
            <input id="username" name="username" type="text" value="{{ old('username') }}" required
                   autocomplete="username" autocapitalize="none" spellcheck="false" class="text-input"
                   @if ($usernameError) aria-invalid="true" aria-describedby="username-error" @endif>
            @if ($usernameError)
                <p id="username-error" class="field-error" role="alert">{{ $usernameError }}</p>
            @endif

            <label for="password" class="field-label mt-4">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" class="text-input"
                   @if ($passwordError) aria-invalid="true" aria-describedby="password-error" @endif>
            @if ($passwordError)
                <p id="password-error" class="field-error" role="alert">{{ $passwordError }}</p>
            @endif

            <div class="btn-row">
                <button type="submit" class="btn btn--primary w-full">Sign in</button>
            </div>
        </form>
    </div>
@endsection
