@if($errors->any())
    <div class="notice notice-error" role="alert" style="margin:1rem;">
        <div><strong>No users were deleted.</strong>
            <ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
        </div>
    </div>
@endif
<form id="bulk-delete-form" method="POST" action="{{ route($bulkDeleteRoute) }}"
      style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;padding:.85rem 1.25rem;border-bottom:1px solid var(--zchpc-border);">
    @csrf
    @method('DELETE')
    <input type="hidden" name="confirm" value="0">
    <span data-selection-count role="status" aria-live="polite" style="font-weight:600;">0 users selected</span>
    <button type="button" class="btn btn-outline" data-clear-selection disabled>Clear selection</button>
    <button type="submit" class="btn btn-outline" data-delete-selected disabled style="color:var(--danger);border-color:var(--danger);">
        <i class="fa-solid fa-trash" aria-hidden="true"></i> Delete selected
    </button>
    <span class="hint">Select users on this page. Changing pages or filters clears the selection.</span>
    <noscript><span class="hint">Enable JavaScript to select and confirm bulk deletion.</span></noscript>
</form>
@push('scripts')
    <script src="{{ asset('js/personnel-selection.js') }}" defer></script>
@endpush
