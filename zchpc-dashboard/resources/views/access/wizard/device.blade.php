@extends('layouts.app')

@section('title', 'Add New User - Select Device')
@section('header', 'Add New User')

@section('content')
@include('access.partials.alerts')
@include('access.partials.steps', ['current' => 2])

<div class="table-card" style="padding:1.5rem;max-width:1100px;">
    <div style="margin-bottom:1.25rem;">
        <h2 style="margin:0;">Step 2 · Select enrollment device</h2>
        <p class="hint" style="margin-top:.3rem;">
            Choose the terminal where <strong>{{ $personnel->name }}</strong> will place a finger.
            Only devices whose controller this application manages can be used.
        </p>
    </div>

    @if($terminals->isEmpty())
        <div class="notice notice-warning">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div>No API-managed terminals are registered.
                <a href="{{ route('terminals.create') }}" style="font-weight:600;color:inherit;text-decoration:underline;">Add a terminal</a> first.
            </div>
        </div>
    @else
        <form method="POST" action="{{ route('access.wizard.device.store') }}">
            @csrf
            <div class="device-grid">
                @foreach($terminals as $terminal)
                    <label class="device-card {{ (int) $selectedTerminalId === $terminal->id ? 'selected' : '' }}" data-device-card>
                        <div style="display:flex;align-items:flex-start;gap:.55rem;">
                            <input type="radio" name="terminal_id" value="{{ $terminal->id }}"
                                   @checked((int) $selectedTerminalId === $terminal->id) required>
                            <div style="flex:1;">
                                <div style="display:flex;justify-content:space-between;gap:.5rem;align-items:flex-start;">
                                    <strong style="font-size:.95rem;">{{ $terminal->name }}</strong>
                                    <span class="badge badge-{{ $terminal->statusTone() === 'muted' ? 'muted' : $terminal->statusTone() }}">{{ $terminal->statusLabel() }}</span>
                                </div>
                                <div class="device-meta">
                                    <span>Device ID {{ $terminal->id }}</span>
                                    <span>{{ $terminal->ip }}:{{ $terminal->port }}</span>
                                    <span>{{ $terminal->location ?: $terminal->phase ?: 'No location' }}</span>
                                </div>
                                <div class="device-meta">
                                    <span>
                                        <i class="fa-solid fa-fingerprint"></i>
                                        {{ $terminal->fingerprintCapabilityLabel() }}
                                    </span>
                                    @if(in_array($terminal->id, $busyTerminalIds, true))
                                        <span class="badge badge-warning">Enrollment in progress</span>
                                    @endif
                                </div>
                                @unless($terminal->isOnline())
                                    <p class="hint" style="color:#b45309;">
                                        Offline now. You can still select it and continue without a fingerprint, or wait until it reconnects.
                                    </p>
                                @endunless
                            </div>
                        </div>
                    </label>
                @endforeach
            </div>

            <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-top:1.5rem;">
                <a href="{{ route('access.users.edit', $personnel) }}" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> Back to details</a>
                <button class="btn btn-primary" type="submit">Continue to fingerprint <i class="fa-solid fa-arrow-right"></i></button>
            </div>
        </form>

        @can('access.manage')
            <form method="POST" action="{{ route('access.wizard.cancel') }}" style="margin-top:1rem;"
                  onsubmit="return confirm('Cancel this setup? The draft and any device identity created for enrollment are removed.')">
                @csrf @method('DELETE')
                <button class="btn btn-outline" style="color:var(--danger);">Cancel setup</button>
            </form>
        @endcan
    @endif
</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('input[name="terminal_id"]').forEach((input) => {
    input.addEventListener('change', () => {
        document.querySelectorAll('[data-device-card]').forEach((card) => card.classList.remove('selected'));
        input.closest('[data-device-card]')?.classList.add('selected');
    });
});
</script>
@endpush
