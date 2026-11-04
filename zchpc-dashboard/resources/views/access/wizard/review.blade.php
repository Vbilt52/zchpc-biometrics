@extends('layouts.app')

@section('title', 'Add New User - Review')
@section('header', 'Add New User')

@section('content')
@include('access.partials.alerts')
@include('access.partials.steps', ['current' => 5])

@php
    $confirmed = $enrollments->where('status', 'confirmed');
    $offlineControllers = $doors->pluck('terminal')->filter()->filter(fn ($terminal) => ! $terminal->isOnline())->unique('id');
@endphp

<div class="table-card" style="padding:1.5rem;max-width:1000px;">
    <h2 style="margin:0 0 1rem;">Step 5 · Review before saving</h2>

    <div class="access-layout" style="margin-bottom:1.25rem;">
        <div style="border:1px solid var(--zchpc-border);border-radius:var(--radius-md);padding:.9rem 1rem;">
            <div class="hint" style="text-transform:uppercase;letter-spacing:.05em;font-size:.68rem;">Person</div>
            <div style="font-weight:700;font-size:1.05rem;margin-top:.2rem;">{{ $personnel->name }}</div>
            <div class="hint"><code>{{ $personnel->employee_no }}</code></div>
            <div class="hint">{{ $personnel->accessGroup?->name ?? $personnel->department }}</div>
            <div class="hint">
                {{ $personnel->begin_time?->timezone(config('attendance.timezone'))->format('d M Y H:i') }}
                –
                {{ $personnel->end_time?->timezone(config('attendance.timezone'))->format('d M Y H:i') }}
            </div>
        </div>

        <div style="border:1px solid var(--zchpc-border);border-radius:var(--radius-md);padding:.9rem 1rem;">
            <div class="hint" style="text-transform:uppercase;letter-spacing:.05em;font-size:.68rem;">Fingerprints</div>
            @forelse($confirmed as $enrollment)
                <div style="font-size:.86rem;margin-top:.25rem;">
                    <i class="fa-solid fa-fingerprint" style="color:var(--success);"></i>
                    {{ $enrollment->fingerLabelText() }} <span class="hint">· {{ $enrollment->terminal_name }}</span>
                </div>
            @empty
                <div style="font-size:.86rem;margin-top:.3rem;color:#b45309;">
                    None captured. The person will be created without a fingerprint.
                </div>
            @endforelse
        </div>

        <div style="border:1px solid var(--zchpc-border);border-radius:var(--radius-md);padding:.9rem 1rem;">
            <div class="hint" style="text-transform:uppercase;letter-spacing:.05em;font-size:.68rem;">Door access</div>
            <div style="font-weight:700;font-size:1.05rem;margin-top:.2rem;">{{ $doors->count() }} door(s)</div>
            <div class="hint">{{ $doors->pluck('terminal_id')->unique()->count() }} controller(s) will be updated</div>
        </div>
    </div>

    <div class="hint" style="text-transform:uppercase;letter-spacing:.05em;font-size:.68rem;margin-bottom:.35rem;">Permissions by controller</div>
    @forelse($byController as $controller => $controllerDoors)
        @php $first = $controllerDoors->first(); @endphp
        <div style="border:1px solid var(--zchpc-border);border-radius:var(--radius-md);padding:.65rem .85rem;margin-bottom:.5rem;">
            <div style="display:flex;justify-content:space-between;gap:.5rem;flex-wrap:wrap;">
                <strong style="font-size:.86rem;">{{ $controller }}</strong>
                <span class="badge badge-{{ $first->terminal?->statusTone() === 'muted' ? 'muted' : $first->terminal?->statusTone() }}">
                    {{ $first->terminal?->statusLabel() ?? 'No controller' }}
                </span>
            </div>
            <div class="hint">{{ $controllerDoors->pluck('name')->implode(', ') }}</div>
        </div>
    @empty
        <div class="notice notice-warning" style="margin-top:.5rem;">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div>
                <strong>No door access selected.</strong>
                The person will be created with no permissions until an administrator grants doors.
            </div>
        </div>
    @endforelse

    @if($offlineControllers->isNotEmpty())
        <div class="notice notice-warning" style="margin-top:.75rem;">
            <i class="fa-solid fa-plug-circle-xmark"></i>
            <div>
                <strong>{{ $offlineControllers->pluck('name')->implode(', ') }} {{ $offlineControllers->count() === 1 ? 'is' : 'are' }} offline.</strong>
                The permission will be saved and queued, but those controllers stay
                <em>pending synchronization</em> until they confirm. A controller that is offline may continue
                enforcing its previously stored permissions in the meantime.
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('access.wizard.deploy') }}" style="margin-top:1.25rem;">
        @csrf
        <label style="display:flex;gap:.6rem;align-items:flex-start;font-size:.85rem;cursor:pointer;padding:.65rem .8rem;border:1px solid var(--zchpc-border);border-radius:var(--radius-md);background:var(--zchpc-bg);">
            <input type="checkbox" name="confirm" value="1" required style="margin-top:.2rem;">
            <span>
                I confirm these permissions. Saving writes the person and the selected doors to the controllers,
                and any door not selected here remains denied.
            </span>
        </label>
        @error('confirm')<p class="form-error">{{ $message }}</p>@enderror

        <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-top:1.25rem;">
            <a href="{{ route('access.wizard.doors') }}" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> Back to doors</a>
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-shield-halved"></i> Save &amp; Apply Access</button>
        </div>
    </form>
</div>
@endsection
