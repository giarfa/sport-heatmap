{{--
    The one-off notice a redirect can land on the home page with (the post-import
    flash). Shared by the dashboard and the empty state, which both show it; the
    caller only decides the width.
--}}
@if (session('status'))
    <div {{ $attributes->class('space-y-3') }}>
        <div role="status" class="card p-4 text-sm" style="color: var(--ink-muted);">
            {{ session('status') }}
        </div>
    </div>
@endif
