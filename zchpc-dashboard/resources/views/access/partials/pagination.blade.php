@if($paginator->hasPages())
    <div style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap;padding:1rem 1.25rem;border-top:1px solid var(--zchpc-border);">
        <span style="color:var(--zchpc-text-muted);font-size:.8rem;">
            Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}
        </span>
        <div style="display:flex;gap:.45rem;align-items:center;">
            @if($paginator->onFirstPage())
                <span class="btn btn-outline" aria-disabled="true" style="opacity:.45;pointer-events:none;"><i class="fa-solid fa-chevron-left"></i> Previous</span>
            @else
                <a class="btn btn-outline" href="{{ $paginator->previousPageUrl() }}"><i class="fa-solid fa-chevron-left"></i> Previous</a>
            @endif
            <span style="color:var(--zchpc-text-muted);font-size:.8rem;">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
            @if($paginator->hasMorePages())
                <a class="btn btn-outline" href="{{ $paginator->nextPageUrl() }}">Next <i class="fa-solid fa-chevron-right"></i></a>
            @else
                <span class="btn btn-outline" aria-disabled="true" style="opacity:.45;pointer-events:none;">Next <i class="fa-solid fa-chevron-right"></i></span>
            @endif
        </div>
    </div>
@endif
