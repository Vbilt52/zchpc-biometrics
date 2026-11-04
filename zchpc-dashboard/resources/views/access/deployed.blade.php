@extends('layouts.app')

@section('title', 'Onboarding result - ' . $personnel->name)
@section('header', 'Onboarding result')

@section('content')
@include('access.partials.alerts')
@include('access.partials.steps', ['current' => 7])

@php
    $pending = collect($controllerStates)->whereIn('state', ['pending', 'revoking']);
    $confirmed = collect($controllerStates)->where('state', 'confirmed');
    $confirmedFingers = $enrollments->where('status', 'confirmed');
@endphp

<div class="table-card" style="padding:1.5rem;margin-bottom:1.25rem;">
    <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start;">
        <div>
            <h2 style="margin:0;">{{ $personnel->name }} is registered</h2>
            <p class="hint" style="margin-top:.3rem;">
                <code>{{ $personnel->employee_no }}</code> · {{ $personnel->accessGroup?->name ?? $personnel->department }}
                · {{ $personnel->accessStateLabel() }}
            </p>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <a href="{{ route('access.users.show', $personnel) }}" class="btn btn-primary">Open profile</a>
            <a href="{{ route('access.users.create') }}" class="btn btn-outline">Add another user</a>
        </div>
    </div>
</div>

<div class="access-layout">
    <div class="table-card">
        <div class="table-header">
            <h2>Step 7 · Controller confirmation</h2>
            <span class="hint">{{ $confirmed->count() }} confirmed · {{ $pending->count() }} pending</span>
        </div>
        @include('access.partials.controller-states', ['controllerStates' => $controllerStates])

        @if($pending->isNotEmpty())
            <div style="padding:0 1.25rem 1.25rem;">
                <div class="notice notice-warning" style="margin:0;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        <strong>Some controllers have not confirmed yet.</strong>
                        The permissions are saved and retried automatically. Until a controller confirms, it may still
                        enforce its previous permissions. Check <a href="{{ route('access.sync.index') }}" style="font-weight:600;color:inherit;text-decoration:underline;">Synchronization</a>
                        for the failure reason.
                    </div>
                </div>
                <form method="POST" action="{{ route('access.users.sync', $personnel) }}" style="margin-top:.6rem;">
                    @csrf
                    <button class="btn btn-outline" type="submit"><i class="fa-solid fa-rotate"></i> Retry synchronization now</button>
                </form>
            </div>
        @endif
    </div>

    <div>
        <div class="table-card" style="padding:1.25rem;margin-bottom:1.25rem;">
            <h3 style="margin:0 0 .5rem;font-size:.95rem;">Fingerprints confirmed</h3>
            @forelse($confirmedFingers as $enrollment)
                <div style="border-bottom:1px solid var(--zchpc-border);padding:.5rem 0;">
                    <div style="font-size:.86rem;"><i class="fa-solid fa-fingerprint" style="color:var(--success);"></i> {{ $enrollment->fingerLabelText() }}</div>
                    <div class="hint">{{ $enrollment->terminal_name }} · {{ $enrollment->finished_at?->format('d M Y H:i') }}</div>
                </div>
            @empty
                <p class="hint">No fingerprint was enrolled. The person can be enrolled from their profile when a terminal is available.</p>
            @endforelse
        </div>

        <div class="table-card" style="padding:1.25rem;">
            <h3 style="margin:0 0 .5rem;font-size:.95rem;">Doors granted</h3>
            @forelse($personnel->doors as $door)
                <div style="border-bottom:1px solid var(--zchpc-border);padding:.5rem 0;">
                    <div style="font-size:.86rem;">{{ $door->name }}</div>
                    <div class="hint">{{ $door->controllerLabel() }}</div>
                </div>
            @empty
                <p class="hint">No door access. This follows least privilege until an administrator grants a door.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
