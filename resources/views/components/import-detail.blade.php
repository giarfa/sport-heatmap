@props(['status'])

{{-- Markup only: every value comes from ImportStatus — no date arithmetic here. --}}
<div class="import-detail">
    <h3 class="text-xs font-semibold">{{ $status->detailHeading() }}</h3>
    <dl>
        @if ($status->syncConfigured)
            <div class="import-detail-row">
                <dt>Last sync</dt>
                <dd class="import-detail-value">
                    {{ $status->lastSyncLabel() }}
                    @if ($status->lastSyncRelative() !== null)
                        <span class="block" style="color: var(--ink-muted);">{{ $status->lastSyncRelative() }}</span>
                    @endif
                </dd>
            </div>
        @endif
        <div class="import-detail-row">
            <dt>Last import</dt>
            <dd class="import-detail-value">
                {{ $status->lastImportLabel() }}
                @if ($status->lastImportRelative() !== null)
                    <span class="block" style="color: var(--ink-muted);">{{ $status->lastImportRelative() }}</span>
                @endif
            </dd>
        </div>
        <div class="import-detail-row">
            <dt>Runs in window</dt>
            <dd class="import-detail-value">{{ $status->runsInWindow }}</dd>
        </div>
    </dl>
</div>
