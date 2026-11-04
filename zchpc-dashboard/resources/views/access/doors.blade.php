@extends('layouts.app')

@section('title', 'Doors & Controllers - ZCHPC Access Control')
@section('header', 'Doors & Controllers')

@section('content')
@include('access.partials.alerts')

<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start;margin-bottom:1rem;">
    <div>
        <h2 style="margin:0;">Door registry</h2>
        <p class="hint" style="margin-top:.3rem;">
            A door maps to one controller channel. Grants are written to that controller only.
        </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        @can('access.manage')
            @if($unregisteredControllers->isNotEmpty())
                <form method="POST" action="{{ route('access.doors.provision') }}" style="margin:0;">
                    @csrf
                    <button class="btn btn-outline" type="submit"><i class="fa-solid fa-plug-circle-plus"></i> Register missing controller doors</button>
                </form>
            @endif
        @endcan
        <a href="{{ route('access.matrix.index') }}" class="btn btn-outline"><i class="fa-solid fa-table-cells"></i> Access matrix</a>
    </div>
</div>

<div class="access-stat-row">
    <div class="access-stat"><div class="value">{{ $stats['doors'] }}</div><div class="label">Registered doors</div></div>
    <div class="access-stat"><div class="value">{{ $stats['active'] }}</div><div class="label">In service</div></div>
    <div class="access-stat"><div class="value">{{ $stats['grants'] }}</div><div class="label">Door grants</div></div>
    <div class="access-stat"><div class="value">{{ $stats['unregistered'] }}</div><div class="label">Controllers without a door</div></div>
</div>

@if($unregisteredControllers->isNotEmpty())
    <div class="notice notice-warning">
        <i class="fa-solid fa-plug-circle-xmark" style="margin-top:.1rem;"></i>
        <div>
            <strong>{{ $unregisteredControllers->pluck('name')->implode(', ') }}</strong>
            {{ $unregisteredControllers->count() === 1 ? 'has' : 'have' }} no registered door.
            Register them so those controllers can be granted access.
        </div>
    </div>
@endif

@can('access.manage')
    <details class="table-card" style="padding:0;margin-bottom:1.25rem;">
        <summary style="padding:1rem 1.25rem;cursor:pointer;font-weight:600;color:var(--zchpc-primary);">
            <i class="fa-solid fa-plus"></i> Register a door
        </summary>
        <form method="POST" action="{{ route('access.doors.store') }}" style="padding:0 1.25rem 1.25rem;">
            @csrf
            @include('access.partials.door-fields', ['door' => null])
            <button class="btn btn-primary" type="submit">Register door</button>
        </form>
    </details>
@endcan

<div class="access-layout">
    <div class="table-card">
        <div class="table-header" style="flex-wrap:wrap;gap:.75rem;">
            <h3 style="margin:0;font-size:.95rem;">Doors</h3>
            <form method="GET" action="{{ route('access.doors.index') }}" style="display:flex;gap:.4rem;flex-wrap:wrap;">
                <input class="form-input" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Door, code, building" style="width:200px;">
                <select class="form-select" name="building" onchange="this.form.submit()" style="width:auto;">
                    <option value="">All buildings</option>
                    @foreach($buildings as $building)
                        <option value="{{ $building }}" @selected(($filters['building'] ?? '') === $building)>{{ $building }}</option>
                    @endforeach
                </select>
                <select class="form-select" name="status" onchange="this.form.submit()" style="width:auto;">
                    <option value="">Any state</option>
                    <option value="active" @selected(($filters['status'] ?? '') === 'active')>In service</option>
                    <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Out of service</option>
                </select>
                <button class="btn btn-outline" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
            </form>
        </div>

        <div class="table-wrapper">
            <table>
                <thead><tr><th>Door</th><th>Area</th><th>Controller</th><th>Grants</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @forelse($doors as $door)
                    <tr>
                        <td>
                            <div style="font-weight:600;">{{ $door->name }}</div>
                            <div class="hint"><code>{{ $door->code }}</code></div>
                        </td>
                        <td>
                            <div>{{ $door->building ?: 'Unassigned building' }}</div>
                            <div class="hint">{{ implode(' · ', array_filter([$door->floor, $door->zone])) ?: 'No floor or zone' }}</div>
                        </td>
                        <td>
                            {{ $door->terminal?->name ?? 'No controller' }}
                            <div class="hint">Channel {{ $door->door_no }} · plan {{ $door->plan_template_no }}</div>
                        </td>
                        <td>{{ $door->granted_people_count }}</td>
                        <td><span class="badge badge-{{ $door->statusTone() === 'muted' ? 'muted' : $door->statusTone() }}">{{ $door->statusLabel() }}</span></td>
                        <td><span class="hint">Open below</span></td>
                    </tr>
                    <tr>
                        <td colspan="6" style="background:var(--zchpc-bg);padding:.5rem 1rem;">
                            <details>
                                <summary style="cursor:pointer;font-size:.8rem;font-weight:600;color:var(--zchpc-primary);">Edit / inspect {{ $door->name }}</summary>
                                @can('access.manage')
                                    <form method="POST" action="{{ route('access.doors.update', $door) }}" style="margin-top:.75rem;">
                                        @csrf @method('PUT')
                                        @include('access.partials.door-fields', ['door' => $door, 'terminals' => $terminals])
                                        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                                            <button class="btn btn-primary" type="submit">Save door</button>
                                        </div>
                                    </form>
                                    <form method="POST" action="{{ route('access.doors.destroy', $door) }}" style="margin-top:.5rem;"
                                          onsubmit="return confirm('Remove this door from the registry? Granted doors must be revoked first.')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-outline" type="submit" style="color:var(--danger);">Remove door</button>
                                    </form>
                                @else
                                    <p class="hint" style="margin-top:.5rem;">View only. An administrator can edit doors.</p>
                                @endcan
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--zchpc-text-muted);">No doors registered yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @include('access.partials.pagination', ['paginator' => $doors])
    </div>

    <div class="table-card" style="padding:1.25rem;">
        <h3 style="margin:0 0 .6rem;font-size:.95rem;">Controllers</h3>
        <p class="hint" style="margin:0 0 .75rem;">
            Remote fingerprint capture is offered until the device itself rejects the capability endpoints.
        </p>

        @forelse($terminals as $terminal)
            <div style="border-bottom:1px solid var(--zchpc-border);padding:.6rem 0;">
                <div style="display:flex;justify-content:space-between;gap:.5rem;flex-wrap:wrap;">
                    <strong style="font-size:.86rem;">{{ $terminal->name }}</strong>
                    <span class="badge badge-{{ $terminal->statusTone() === 'muted' ? 'muted' : $terminal->statusTone() }}">{{ $terminal->statusLabel() }}</span>
                </div>
                <div class="hint">{{ $terminal->ip }}:{{ $terminal->port }} · {{ $terminal->doors_count }} door(s)</div>
                <div class="hint">
                    <i class="fa-solid fa-fingerprint"></i> {{ $terminal->fingerprintCapabilityLabel() }}
                    @if($terminal->fp_checked_at) · checked {{ $terminal->fp_checked_at->diffForHumans() }} @endif
                </div>
                @can('access.manage')
                    <form method="POST" action="{{ route('access.doors.probe', $terminal) }}" style="margin-top:.35rem;">
                        @csrf
                        <button class="btn btn-outline" style="padding:.25rem .5rem;font-size:.72rem;" type="submit">
                            Check fingerprint API
                        </button>
                    </form>
                @endcan
            </div>
        @empty
            <p class="hint">No API-managed controllers.</p>
        @endforelse

        <a href="{{ route('terminals.index') }}" class="btn btn-outline" style="margin-top:.75rem;width:100%;justify-content:center;">
            <i class="fa-solid fa-server"></i> Manage terminals
        </a>
    </div>
</div>
@endsection
