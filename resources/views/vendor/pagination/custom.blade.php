@if ($paginator->hasPages())
    <nav style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
        @if ($paginator->onFirstPage())
            <span class="btn btn-ghost" style="opacity: 0.4; pointer-events: none;">&laquo; Prev</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="btn btn-ghost">&laquo; Prev</a>
        @endif

        <span style="color: var(--ink-muted); padding: 0 8px;">
            Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}
            &middot; {{ number_format($paginator->total()) }} total
        </span>

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="btn btn-ghost">Next &raquo;</a>
        @else
            <span class="btn btn-ghost" style="opacity: 0.4; pointer-events: none;">Next &raquo;</span>
        @endif
    </nav>
@endif
