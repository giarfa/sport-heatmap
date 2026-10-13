@props(['navigation'])

{{--
    Markup only: the URLs and which controls are available come from WindowNavigation.
    A disabled control is a disabled <button>, never an <a>: a link with no href is not
    focusable and is not announced as disabled, and a button says both for free. The
    arrows are decoration; the visible word is part of the accessible name (WCAG 2.5.3).
--}}
<nav class="win-nav mt-3" aria-label="Window">
    @if ($navigation->previousUrl !== null)
        <a href="{{ $navigation->previousUrl }}" class="win-btn"><span aria-hidden="true">&larr;</span> Previous<span class="sr-only"> 4 weeks</span></a>
    @else
        <button type="button" class="win-btn win-btn--off" disabled><span aria-hidden="true">&larr;</span> Previous<span class="sr-only"> 4 weeks</span></button>
    @endif

    @if ($navigation->nextUrl !== null)
        <a href="{{ $navigation->nextUrl }}" class="win-btn">Next<span class="sr-only"> 4 weeks</span> <span aria-hidden="true">&rarr;</span></a>
    @else
        <button type="button" class="win-btn win-btn--off" disabled>Next<span class="sr-only"> 4 weeks</span> <span aria-hidden="true">&rarr;</span></button>
    @endif
</nav>
