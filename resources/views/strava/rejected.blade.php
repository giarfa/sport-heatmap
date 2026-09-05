@php($reason = $reason ?? 'allowlist')
@php($athleteId = $athleteId ?? null)
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Not connected — Sport Heatmap</title>
</head>
<body>
    <a href="#main">Skip to content</a>
    <main id="main">
        @if ($reason === 'scope')
            <h1>Read access is required</h1>
            <p>
                Sport Heatmap needs the <code>{{ $requiredScope ?? 'activity:read_all' }}</code> permission to read
                your activities. It was not granted, so no session was created and nothing was stored.
            </p>
        @elseif ($reason === 'exchange')
            <h1>Strava could not be reached</h1>
            <p>
                Strava did not complete the authorization. Nothing was created and nothing was stored —
                try again in a moment.
            </p>
        @else
            <h1>This instance is private</h1>
            <p>
                Athlete <span>#{{ $athleteId }}</span> is not on this instance's allowlist,
                so no session was created and nothing was stored.
            </p>
        @endif

        <a href="{{ route('dashboard') }}">Back</a>
    </main>
</body>
</html>
