{{--
    The one-off notices a redirect can land on the home page with. Shared by the
    dashboard and the connect screen because the OAuth cancel path redirects to
    `dashboard`, which a signed-out visitor sees as the public map whenever an
    athlete can be displayed — an allowlisted one who has connected, else a
    stored athlete with activities — and as the connect screen otherwise. Both must
    show the notice; the caller only decides the width.

    `strava.reconnect` is flashed by EnsureFreshStravaToken when it signs an
    owner out over a dead token, so the banner shows once and not on reload.
--}}
@php($reconnect = (bool) session('strava.reconnect'))

@if (session('status') || $reconnect)
    <div {{ $attributes->class('space-y-3') }}>
        {{-- Set when the athlete pressed Cancel on Strava's consent screen. --}}
        @if (session('status'))
            <div role="status" class="card p-4 text-sm" style="color: var(--ink-muted);">
                {{ session('status') }}
            </div>
        @endif

        @if ($reconnect)
            <div role="alert" class="card p-4 text-sm"
                 style="background: var(--warn-bg); border-color: var(--warn-border); color: var(--warn-ink);">
                Your Strava connection expired. Connect again to keep the heatmap up to date.
            </div>
        @endif
    </div>
@endif
