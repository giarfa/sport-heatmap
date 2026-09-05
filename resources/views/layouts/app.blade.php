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

    <title>@yield('title', 'Sport Heatmap')</title>
    <meta name="description" content="Private training dashboard — daily running distance over the last 16 weeks.">

    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen font-sans antialiased">
    <a href="#main" class="skip-link">Skip to content</a>

    <header class="border-b" style="background: var(--surface-card); border-color: var(--hairline);">
        <div class="mx-auto flex h-14 max-w-5xl items-center px-3 sm:px-6">
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
        </div>
    </header>

    <main id="main" class="mx-auto max-w-5xl px-3 py-6 sm:px-6">
        @yield('content')
    </main>

    <footer class="mx-auto max-w-5xl px-3 pb-8 sm:px-6">
        <p class="text-xs" style="color: var(--ink-muted);">Private instance — one athlete, one screen.</p>
    </footer>
</body>
</html>
