@extends('layouts.app')

@section('title', 'Add New User - Fingerprint')
@section('header', 'Add New User')

@section('content')
@include('access.partials.alerts')
@include('access.partials.steps', ['current' => 3])

@php
    $confirmed = $enrollments->where('status', 'confirmed');
    $confirmedFingers = $confirmed->pluck('finger_id')->all();
    $latest = $activeEnrollment ?? $enrollments->sortByDesc('id')->first();
@endphp

<div class="access-layout">
    <div class="table-card" style="padding:1.5rem;">
        <h2 style="margin:0 0 .35rem;">Step 3 · Capture fingerprint</h2>
        <p class="hint" style="margin:0 0 1rem;">
            The terminal enters capture mode and waits for the person. Nothing is claimed until the terminal confirms the slot.
            The capture stays on this terminal only; enroll separately on each other terminal that needs the fingerprint.
        </p>

        <div style="border:1px solid var(--zchpc-border);border-radius:var(--radius-md);padding:.85rem 1rem;margin-bottom:1rem;">
            <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;">
                <strong>{{ $terminal->name }}</strong>
                <span class="badge badge-{{ $terminal->statusTone() === 'muted' ? 'muted' : $terminal->statusTone() }}">{{ $terminal->statusLabel() }}</span>
            </div>
            <div class="hint" style="margin-top:.25rem;">
                {{ $terminal->ip }}:{{ $terminal->port }} · {{ $terminal->location ?: $terminal->phase ?: 'No location' }}
                · {{ $terminal->fingerprintCapabilityLabel() }}
            </div>
        </div>

        @if(! $terminal->isOnline() || ! $terminal->supportsFingerprintEnrollment())
            <div class="notice notice-warning">
                <i class="fa-solid fa-triangle-exclamation" style="margin-top:.1rem;"></i>
                <div>
                    <strong>Remote capture is not available right now.</strong>
                    <p style="margin:.3rem 0 .5rem;font-size:.85rem;">
                        {{ ! $terminal->isOnline()
                            ? $terminal->name.' is offline, so it cannot be asked to enter capture mode.'
                            : $terminal->name.' has confirmed that it does not expose the remote fingerprint capture API.' }}
                    </p>
                    <p style="margin:0;font-size:.85rem;">
                        Supported alternative: enroll the person directly on the terminal (add employee
                        <code>{{ $personnel->employee_no }}</code>), then import the record from the device.
                        You can continue below and grant doors now; the fingerprint can be enrolled again from the profile later.
                    </p>
                </div>
            </div>
        @endif

        @include('access.partials.enrollment-status', [
            'enrollment' => $activeEnrollment ?? null,
            'cancelUrl' => $activeEnrollment ? route('access.wizard.capture.cancel', $activeEnrollment) : null,
        ])

        @if(! $activeEnrollment)
            <form method="POST" action="{{ route('access.wizard.capture.start') }}" style="margin-top:1rem;">
                @csrf
                <div class="form-grid">
                    <div class="form-field">
                        <label class="form-label" for="finger_id">Which finger?</label>
                        <select class="form-select" id="finger_id" name="finger_id" @disabled(! $terminal->isOnline())>
                            @foreach($fingerIds as $fingerId)
                                <option value="{{ $fingerId }}" @disabled(in_array($fingerId, $confirmedFingers, true))>
                                    {{ \App\Models\FingerprintEnrollment::fingerLabel($fingerId) }}
                                    {{ in_array($fingerId, $confirmedFingers, true) ? ' — already enrolled' : '' }}
                                </option>
                            @endforeach
                        </select>
                        <p class="hint">A separate enrollment is requested for each finger.</p>
                        @error('finger')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <button class="btn btn-primary" type="submit" @disabled(! $terminal->isOnline())>
                    <i class="fa-solid fa-fingerprint"></i> Start Fingerprint Enrollment
                </button>
            </form>
        @endif

        @error('terminal')<p class="form-error">{{ $message }}</p>@enderror
    </div>

    <div class="table-card" style="padding:1.25rem;">
        <h3 style="margin:0 0 .5rem;font-size:.95rem;">Fingers enrolled in this setup</h3>
        @forelse($enrollments as $enrollment)
            <div style="border-bottom:1px solid var(--zchpc-border);padding:.55rem 0;">
                <div style="display:flex;justify-content:space-between;gap:.5rem;">
                    <span style="font-size:.85rem;">{{ $enrollment->fingerLabelText() }}</span>
                    <span class="badge badge-{{ $enrollment->tone() === 'muted' ? 'muted' : $enrollment->tone() }}">{{ $enrollment->label() }}</span>
                </div>
                @if(in_array($enrollment->status, ['failed', 'timed_out', 'cancelled'], true))
                    <form method="POST" action="{{ route('access.wizard.capture.retry', $enrollment) }}" style="margin-top:.35rem;">
                        @csrf
                        <button class="btn btn-outline" style="padding:.25rem .5rem;font-size:.72rem;">Retry</button>
                    </form>
                @endif
            </div>
        @empty
            <p class="hint">No fingerprint attempt yet.</p>
        @endforelse

        <div style="margin-top:1rem;display:flex;flex-direction:column;gap:.5rem;">
            @if($confirmed->isNotEmpty())
                <a href="{{ route('access.wizard.doors') }}" class="btn btn-primary" style="justify-content:center;">
                    Continue to door access <i class="fa-solid fa-arrow-right"></i>
                </a>
            @elseif(! $activeEnrollment)
                <form method="POST" action="{{ route('access.wizard.capture.skip') }}">
                    @csrf
                    <button class="btn btn-outline" style="width:100%;justify-content:center;" type="submit">
                        Continue without fingerprint
                    </button>
                </form>
                <p class="hint">Use this only when the device cannot capture remotely. The person can be enrolled locally and imported later.</p>
            @endif

            <a href="{{ route('access.wizard.device') }}" class="btn btn-outline" style="justify-content:center;">
                <i class="fa-solid fa-arrow-left"></i> Change device
            </a>
        </div>
    </div>
</div>
@endsection
