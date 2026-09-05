@php($reconnect = $reconnect ?? false)
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Connect Strava — Sport Heatmap</title>
</head>
<body>
    <a href="#main">Skip to content</a>
    <main id="main">
        @if ($reconnect)
            <p role="alert">Your Strava connection expired. Connect again to keep the heatmap up to date.</p>
        @endif

        <h1>See your training at a glance</h1>

        <p>
            Connect Strava to build your 16-week heatmap. Read-only access to your activities —
            nothing is ever posted on your behalf.
        </p>

        <a href="{{ route('strava.redirect') }}">Connect with Strava</a>

        <p>Requests the <code>activity:read_all</code> scope.</p>
    </main>
</body>
</html>
