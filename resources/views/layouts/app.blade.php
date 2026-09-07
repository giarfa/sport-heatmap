{{--
    The shell every screen in this product sits on. US-004, US-005 and US-006 fill
    in the header chip, the theme toggle and the grid; the landmarks, the skip link
    and the focus contract are settled here and must survive those specs.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Private single-athlete instance: there is nothing here to index. --}}
    <meta name="robots" content="noindex, nofollow">

    {{--
        The OAuth callback URL carries the authorization code in its query
        string, and the rejection screens render on that URL. Browsers default to
        strict-origin-when-cross-origin, which already truncates it; this pins
        that rather than inheriting it.
    --}}
    <meta name="referrer" content="same-origin">

    <title>@yield('title', 'Sport Heatmap')</title>
    <meta name="description" content="Private training dashboard — daily running distance over the last 16 weeks.">

    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

    {{--
        Inline and blocking, on purpose: this must run before the stylesheet
        paints anything. Deferring it to the bundle is what causes the flash of
        the wrong theme on a dark-theme reload. Unset leaves both classes off
        so prefers-color-scheme decides.
    --}}
    <script>
        (function () {
            var stored = localStorage.getItem('theme');
            if (stored === 'dark') {
                document.documentElement.classList.add('dark');
            } else if (stored === 'light') {
                document.documentElement.classList.add('light');
            }
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen font-sans antialiased">
    <a href="#main" class="skip-link">Skip to content</a>

    <header class="sticky top-0 z-10 border-b" style="background: var(--surface-card); border-color: var(--hairline);">
        <div class="mx-auto flex h-14 max-w-5xl items-center justify-between px-3 sm:px-6">
            {{--
                The mark is inlined and the wordmark is live text rather than an
                <img> of logo.svg: the wordmark is currentColor, which an <img>
                would resolve to black on both themes. public/images/brand/logo.svg
                remains the canonical asset for anything outside the app.

                No nav and no hamburger, deliberately — one screen, one user.
            --}}
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2" style="color: var(--ink);">
                <svg width="22" height="22" viewBox="0 0 32 32" aria-hidden="true" class="shrink-0">
                    <rect x="3" y="17" width="12" height="12" rx="3" fill="#86b6ef"/>
                    <rect x="17" y="17" width="12" height="12" rx="3" fill="#2a78d6"/>
                    <rect x="3" y="3" width="12" height="12" rx="3" fill="#3987e5"/>
                    <rect x="17" y="3" width="12" height="12" rx="3" fill="#104281"/>
                </svg>
                <span class="text-sm font-semibold tracking-tight">Heatmap</span>
            </a>
            <div class="flex items-center gap-1">
                {{--
                    A yield, not a view composer or a shared variable: strava/connect
                    and strava/rejected extend this same layout and must render with
                    no chip at all, which a composer could not express as cleanly.
                --}}
                @yield('header-status')
                <button type="button" data-theme-toggle aria-pressed="false"
                        class="grid h-11 w-11 place-items-center rounded-lg" style="color: var(--ink-muted);">
                    <span class="sr-only">Toggle dark mode</span>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                    </svg>
                </button>
            </div>
        </div>
    </header>

    <main id="main" class="mx-auto max-w-5xl px-3 py-6 sm:px-6">
        @yield('content')
    </main>

    <footer class="mx-auto max-w-5xl px-3 pb-8 sm:px-6">
        <p class="text-xs" style="color: var(--ink-muted);">
            {{-- Contractual under the Strava API Agreement (FR-008), not decoration — it stays in the layout so no view change can drop it. --}}
            <a href="https://www.strava.com" class="underline underline-offset-2" style="color: var(--accent);">Powered by Strava</a>
            · runs only · distances in kilometres
            <span class="sm:hidden">@yield('footer-status')</span>
        </p>
    </footer>
</body>
</html>
