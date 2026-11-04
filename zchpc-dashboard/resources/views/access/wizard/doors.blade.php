@extends('layouts.app')

@section('title', 'Add New User - Door Access')
@section('header', 'Add New User')

@section('content')
@include('access.partials.alerts')
@include('access.partials.steps', ['current' => 4])

<form method="POST" action="{{ route('access.wizard.doors.store') }}" id="door-form">
    @csrf
    <div class="table-card" style="padding:1.5rem;max-width:1100px;">
        <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start;margin-bottom:.75rem;">
            <div>
                <h2 style="margin:0;">Step 4 · Doors for {{ $personnel->name }}</h2>
                <p class="hint" style="margin-top:.3rem;">
                    Nothing is granted by default. Select only the doors this person needs; unrelated doors stay closed.
                </p>
            </div>
            <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                <button type="button" class="btn btn-outline" id="select-all-doors" style="padding:.4rem .7rem;">Select all</button>
                <button type="button" class="btn btn-outline" id="clear-all-doors" style="padding:.4rem .7rem;">Clear</button>
            </div>
        </div>

        @if($accessGroups->isNotEmpty())
            <div style="margin:0 0 1rem;">
                <span class="form-label">Apply a predefined access group</span>
                <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                    @foreach($accessGroups as $group)
                        <button type="button" class="btn btn-outline js-apply-group"
                                data-door-ids="{{ $group->doors->pluck('id')->toJson() }}"
                                style="padding:.35rem .65rem;font-size:.78rem;">
                            <i class="fa-solid fa-layer-group"></i> {{ $group->name }}
                            <span class="hint">({{ $group->doors->count() }})</span>
                        </button>
                    @endforeach
                </div>
                <p class="hint">A group only ticks its doors below; you still review and confirm them.</p>
            </div>
        @else
            <p class="hint" style="margin-bottom:1rem;">
                No access group has doors configured yet.
                <a href="{{ route('access-groups.index') }}" style="color:var(--zchpc-primary);font-weight:600;">Configure a group</a>
                to apply preset door sets here.
            </p>
        @endif

        @include('access.partials.door-tree', ['doors' => $doors, 'selectedDoorIds' => $selectedDoorIds])
        @error('door_ids')<p class="form-error">{{ $message }}</p>@enderror

        <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-top:1.5rem;">
            <a href="{{ route('access.wizard.capture') }}" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> Back to fingerprint</a>
            <button class="btn btn-primary" type="submit">Review permissions <i class="fa-solid fa-arrow-right"></i></button>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
(() => {
    const form = document.getElementById('door-form');
    if (!form) return;

    document.getElementById('select-all-doors')?.addEventListener('click', () => {
        form.querySelectorAll('input[name="door_ids[]"]').forEach((box) => { box.checked = true; });
    });

    document.getElementById('clear-all-doors')?.addEventListener('click', () => {
        form.querySelectorAll('input[name="door_ids[]"]').forEach((box) => { box.checked = false; });
    });

    form.querySelectorAll('.js-apply-group').forEach((button) => {
        button.addEventListener('click', () => {
            const ids = JSON.parse(button.dataset.doorIds || '[]');
            ids.forEach((id) => {
                const box = form.querySelector('input[name="door_ids[]"][value="' + id + '"]');
                if (box) box.checked = true;
            });
        });
    });
})();
</script>
@endpush
