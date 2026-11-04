@extends('layouts.app')

@section('title', 'Terminals - ZCHPC Access Control')
@section('header', 'Terminal Management')

@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <p style="color: var(--zchpc-text-muted); font-size: 0.9rem; margin: 0;">
        Manage physical Hikvision terminals across all ZCHPC buildings.
    </p>
    <div style="display: flex; gap: 1rem;">
        <a href="{{ route('terminals.index') }}" class="btn btn-outline animate-fade-in" title="Refresh Status">
            <i class="fa-solid fa-rotate-right"></i> Refresh
        </a>
        <a href="{{ route('terminals.create') }}" class="btn btn-primary animate-fade-in">
            <i class="fa-solid fa-plus"></i> Add Terminal
        </a>
    </div>
</div>

@if(session('success'))
    <div style="background: rgba(16,185,129,0.1); color: var(--success); padding: 1rem; border-radius: var(--radius-md); margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem;">
        <i class="fa-solid fa-circle-check"></i> {{ session('success') }}
    </div>
@endif

@if(session('error'))
    <div role="alert" style="background:#fef2f2;color:#991b1b;padding:1rem;border:1px solid #fecaca;border-radius:var(--radius-md);margin-bottom:1.5rem;">
        {{ session('error') }}
    </div>
@endif

<div class="table-card animate-fade-in">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Terminal Name</th>
                    <th>Phase</th>
                    <th>IP Address</th>
                    <th>API Capabilities</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($terminals->sortBy('phase') as $terminal)
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 0.65rem;">
                            <div style="width: 34px; height: 34px; background: rgba(0,74,134,0.1); border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                <i class="fa-solid fa-server" style="font-size: 0.85rem; color: var(--zchpc-primary);"></i>
                            </div>
                            <strong>{{ $terminal->name }}</strong>
                        </div>
                    </td>
                    <td>
                        <span style="display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.8rem; padding: 0.25rem 0.65rem; background: rgba(0,74,134,0.07); color: var(--zchpc-primary); border-radius: var(--radius-md); font-weight: 600;">
                            <i class="fa-solid fa-building"></i> {{ $terminal->phase }}
                        </span>
                    </td>
                    <td style="font-family: monospace; font-size: 0.875rem;">
                        {{ strtoupper($terminal->protocol) }}://{{ $terminal->ip }}:{{ $terminal->port }}
                    </td>
                    <td>
                        <div style="display: flex; gap: 0.4rem; flex-wrap: wrap;">
                            <span style="display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.72rem; padding: 0.2rem 0.5rem; background: rgba(0,74,134,0.07); color: var(--zchpc-primary); border-radius: 999px; font-weight: 600;">
                                <i class="fa-solid fa-plug"></i> {{ $terminal->integrationLabel() }}
                            </span>
                            @if($terminal->supports('door_control'))<span class="badge badge-success">Door API</span>@endif
                            @if($terminal->supports('event_import'))<span class="badge badge-info">Logs API</span>@endif
                            @if($terminal->supports('fingerprint_enrollment'))<span class="badge badge-info">Biometric API</span>@endif
                            @if(!$terminal->isApiManaged())<span class="badge badge-muted">No remote API</span>@endif
                        </div>
                        @if($terminal->model)<div style="margin-top:.35rem;font-size:.72rem;color:var(--zchpc-text-muted);">{{ $terminal->model }}</div>@endif
                    </td>
                    <td>
                        @if(!$terminal->isApiManaged())
                            <span class="badge badge-muted">Local controller</span>
                        @elseif($statuses[$terminal->id] ?? false)
                            <span class="badge badge-success"><i class="fa-solid fa-circle" style="font-size: 0.45rem; vertical-align: middle; margin-right: 0.25rem;"></i> Online</span>
                        @else
                            <span class="badge badge-danger"><i class="fa-solid fa-circle" style="font-size: 0.45rem; vertical-align: middle; margin-right: 0.25rem;"></i> Offline</span>
                        @endif
                        
                        @if($terminal->verify_ssl)
                            <i class="fa-solid fa-shield-halved" style="color: var(--success); margin-left: 0.5rem;" title="SSL Enabled"></i>
                        @endif
                    </td>
                    <td>
                        <div style="display: flex; gap: 0.5rem;">
                            <a href="{{ route('terminals.edit', $terminal) }}" class="btn btn-outline" style="padding: 0.3rem 0.65rem; font-size: 0.8rem;">
                                <i class="fa-solid fa-pen"></i> Edit
                            </a>
                            <form action="{{ route('terminals.destroy', $terminal) }}" method="POST" onsubmit="return confirm('Remove terminal \'{{ $terminal->name }}\'?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline" style="padding: 0.3rem 0.65rem; font-size: 0.8rem; color: var(--danger); border-color: var(--danger);">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" style="text-align: center; padding: 4rem; color: var(--zchpc-text-muted);">
                        <i class="fa-solid fa-server" style="font-size: 2.5rem; opacity: 0.2; display: block; margin-bottom: 1rem;"></i>
                        <strong style="display: block; margin-bottom: 0.5rem;">No terminals configured</strong>
                        <span style="font-size: 0.875rem;">Add a terminal to start managing access control across ZCHPC buildings.</span>
                        <br><br>
                        <a href="{{ route('terminals.create') }}" class="btn btn-primary" style="margin-top: 0.5rem;">Add First Terminal</a>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
