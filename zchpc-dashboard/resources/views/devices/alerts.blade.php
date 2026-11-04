@extends('layouts.app')

@section('title', 'Device Alerts - ZCHPC Access Control')
@section('header', 'Device Alerts')

@push('styles')
    <link rel="stylesheet" href="/css/devices.css">
@endpush

@section('content')
    @if(session('success'))
        <div style="background: rgba(16,185,129,0.1); color: #047857; padding: 1rem; border-radius: var(--radius-md); margin-bottom: 1.25rem;">
            <i class="fa-solid fa-circle-check"></i> {{ session('success') }}
        </div>
    @endif

    <div class="device-toolbar">
        <div>
            <p style="margin:0;color:var(--zchpc-text-muted);font-size:.9rem;">
                Offline, restored, authentication, repeated-failure and synchronization alerts. Repeated alerts inside the cooldown window are counted on the existing alert.
            </p>
            <p style="margin:.35rem 0 0;font-size:.82rem;color:var(--zchpc-text-muted);">
                {{ $openCount }} open alert(s), {{ $criticalCount }} critical.
            </p>
        </div>
        <div class="device-actions">
            <a href="{{ route('devices.index') }}" class="btn btn-outline"><i class="fa-solid fa-tower-broadcast"></i> Devices</a>
            @can('devices.alerts.acknowledge')
                @if($openCount > 0)
                    <form method="POST" action="{{ route('devices.alerts.acknowledge-all') }}">
                        @csrf
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check-double"></i> Acknowledge all</button>
                    </form>
                @endif
            @endcan
        </div>
    </div>

    <form method="GET" action="{{ route('devices.alerts.index') }}" class="device-filters">
        <select name="state" aria-label="Acknowledgement state">
            <option value="open" @selected($filters['state'] === 'open')>Open</option>
            <option value="acknowledged" @selected($filters['state'] === 'acknowledged')>Acknowledged</option>
            <option value="all" @selected($filters['state'] === 'all')>All</option>
        </select>
        <select name="severity" aria-label="Severity">
            <option value="">All severities</option>
            @foreach(['critical', 'warning', 'info'] as $severity)
                <option value="{{ $severity }}" @selected($filters['severity'] === $severity)>{{ ucfirst($severity) }}</option>
            @endforeach
        </select>
        <select name="type" aria-label="Alert type">
            <option value="">All types</option>
            @foreach($types as $type)
                <option value="{{ $type }}" @selected($filters['type'] === $type)>{{ str_replace('_', ' ', ucfirst($type)) }}</option>
            @endforeach
        </select>
        <select name="terminal_id" aria-label="Device">
            <option value="">All devices</option>
            @foreach($terminals as $terminal)
                <option value="{{ $terminal->id }}" @selected($filters['terminal_id'] === $terminal->id)>{{ $terminal->name }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-primary btn-xs"><i class="fa-solid fa-filter"></i> Apply</button>
        <a href="{{ route('devices.alerts.index') }}" class="btn btn-outline btn-xs">Clear</a>
    </form>

    <div class="table-card">
        <div class="table-header">
            <h2>Alert history</h2>
            <span class="device-note">Times in {{ config('attendance.timezone') }}</span>
        </div>
        <div class="table-wrapper">
            <table class="device-table">
                <thead>
                    <tr>
                        <th>Last occurred</th>
                        <th>Device</th>
                        <th>Type / severity</th>
                        <th>Detail</th>
                        <th>Count</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($alerts as $alert)
                        <tr>
                            <td>
                                {{ $alert->last_occurred_at?->copy()->timezone(config('attendance.timezone'))->format('d M Y H:i:s') }}
                                @if($alert->first_occurred_at && $alert->first_occurred_at->lt($alert->last_occurred_at))
                                    <div class="device-meta">First: {{ $alert->first_occurred_at->copy()->timezone(config('attendance.timezone'))->format('d M Y H:i') }}</div>
                                @endif
                            </td>
                            <td>
                                <div class="device-name">{{ $alert->terminal_name ?: 'All devices' }}</div>
                                @if($alert->terminal)
                                    <a href="{{ route('devices.index', ['search' => $alert->terminal_name]) }}" class="device-meta">Open device</a>
                                @endif
                            </td>
                            <td>
                                <div>{{ $alert->typeLabel() }}</div>
                                <span class="badge badge-{{ $alert->tone() }}">{{ $alert->severityLabel() }}</span>
                            </td>
                            <td><span style="font-size:.82rem;">{{ $alert->message }}</span></td>
                            <td>{{ $alert->occurrences }}</td>
                            <td>
                                @if($alert->acknowledged_at)
                                    <span class="badge badge-muted">Acknowledged</span>
                                    <div class="device-meta">{{ $alert->acknowledged_by_name }}<br>{{ $alert->acknowledged_at->copy()->timezone(config('attendance.timezone'))->format('d M H:i') }}</div>
                                @else
                                    <span class="badge badge-{{ $alert->tone() }}">Open</span>
                                @endif
                            </td>
                            <td>
                                @can('devices.alerts.acknowledge')
                                    @if(! $alert->acknowledged_at)
                                        <form method="POST" action="{{ route('devices.alerts.acknowledge', $alert) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-outline btn-xs"><i class="fa-solid fa-check"></i> Acknowledge</button>
                                        </form>
                                    @else
                                        <span class="device-note">—</span>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="text-align:center;padding:3rem 1rem;color:var(--zchpc-text-muted);">
                                <i class="fa-solid fa-circle-check" style="font-size:2rem;display:block;margin-bottom:.6rem;opacity:.35;"></i>
                                No alerts match this filter.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div style="margin-top:1rem;">{{ $alerts->links() }}</div>
@endsection
