@props(['status'])

{{-- Markup only: every value comes from SyncStatus — no date arithmetic here. --}}
<div class="sync-detail">
    <h3 class="text-xs font-semibold">{{ $status->detailHeading() }}</h3>
    <dl>
        <div class="sync-detail-row">
            <dt>{{ $status->lastRunHeading() }}</dt>
            <dd class="sync-detail-value">
                {{ $status->lastRunLabel() }}
                @if ($status->lastRunRelative() !== null)
                    <span class="block" style="color: var(--ink-muted);">{{ $status->lastRunRelative() }}</span>
                @endif
            </dd>
        </div>
        <div class="sync-detail-row">
            <dt>Outcome</dt>
            <dd class="sync-detail-value sync-detail-outcome">
                @if ($status->outcomeModifier() !== null)
                    <span aria-hidden="true" class="sync-dot sync-dot--{{ $status->outcomeModifier() }}"></span>
                @endif
                {{ $status->outcomeLabel() }}
            </dd>
        </div>
        <div class="sync-detail-row">
            <dt>Runs in window</dt>
            <dd class="sync-detail-value">{{ $status->runsInWindow }}</dd>
        </div>
    </dl>
</div>
