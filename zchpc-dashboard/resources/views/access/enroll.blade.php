@extends('layouts.app')

@section('title', 'Fingerprint enrollment - ' . $personnel->name)
@section('header', 'Fingerprint enrollment')

@section('content')
@include('access.partials.alerts')

<div class="access-layout">
    <div class="table-card" style="padding:1.5rem;">
        <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start;margin-bottom:1rem;">
            <div>
                <h2 style="margin:0;">{{ $personnel->name }}</h2>
                <p class="hint" style="margin-top:.25rem;"><code>{{ $personnel->employee_no }}</code> · {{ $personnel->accessGroup?->name ?? $personnel->department }}</p>
            </div>
            <a href="{{ route('access.users.show', $personnel) }}" class="btn btn-outline">Back to profile</a>
        </div>

        <div class="notice notice-info">
            <i class="fa-solid fa-circle-info" style="margin-top:.1rem;"></i>
            <div style="font-size:.85rem;">
                Remote capture requires an online terminal that exposes the fingerprint capture API.
                If the device does not support it, enroll on the terminal itself and import the person from the device.
            </div>
        </div>

        @include('access.partials.enrollment-status', [
            'enrollment' => $activeEnrollment,
            'cancelUrl' => $activeEnrollment ? route('access.users.enroll.cancel', [$personnel, $activeEnrollment]) : null,
        ])

        @unless($activeEnrollment)
            <form method="POST" action="{{ route('access.users.enroll.start', $personnel) }}" style="margin-top:.75rem;">
                @csrf
                <div class="form-grid">
                    <div class="form-field">
                        <label class="form-label" for="terminal_id">Terminal</label>
                        <select class="form-select" id="terminal_id" name="terminal_id" required>
                            @foreach($terminals as $terminal)
                                <option value="{{ $terminal->id }}" @disabled(! $terminal->isOnline())>
                                    {{ $terminal->name }} · {{ $terminal->statusLabel() }}
                                    {{ ! $terminal->isOnline() ? ' (offline)' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @if($terminals->isEmpty())<p class="form-error">No API-managed terminals are registered.</p>@endif
                    </div>
                    <div class="form-field">
                        <label class="form-label" for="finger_id">Finger</label>
                        <select class="form-select" id="finger_id" name="finger_id" required>
                            @foreach($fingerIds as $fingerId)
                                <option value="{{ $fingerId }}">{{ \App\Models\FingerprintEnrollment::fingerLabel($fingerId) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                @error('terminal')<p class="form-error">{{ $message }}</p>@enderror
                @error('finger')<p class="form-error">{{ $message }}</p>@enderror
                <button class="btn btn-primary" type="submit" @disabled($terminals->isEmpty())>
                    <i class="fa-solid fa-fingerprint"></i> Start enrollment
                </button>
            </form>
        @endunless
    </div>

    <div class="table-card" style="padding:1.25rem;">
        <h3 style="margin:0 0 .5rem;font-size:.95rem;">Enrollment history</h3>
        @forelse($enrollments as $enrollment)
            <div style="border-bottom:1px solid var(--zchpc-border);padding:.55rem 0;">
                <div style="display:flex;justify-content:space-between;gap:.5rem;flex-wrap:wrap;">
                    <span style="font-size:.84rem;">{{ $enrollment->fingerLabelText() }}</span>
                    <span class="badge badge-{{ $enrollment->tone() === 'muted' ? 'muted' : $enrollment->tone() }}">{{ $enrollment->label() }}</span>
                </div>
                <div class="hint">{{ $enrollment->terminal_name }} · {{ $enrollment->created_at?->format('d M Y H:i') }}</div>
                @if(in_array($enrollment->status, ['failed', 'timed_out', 'cancelled'], true))
                    <form method="POST" action="{{ route('access.users.enroll.retry', [$personnel, $enrollment]) }}" style="margin-top:.3rem;">
                        @csrf
                        <button class="btn btn-outline" style="padding:.25rem .5rem;font-size:.72rem;">Retry</button>
                    </form>
                @endif
            </div>
        @empty
            <p class="hint">No fingerprint enrollment has been recorded for this person.</p>
        @endforelse
    </div>
</div>
@endsection
