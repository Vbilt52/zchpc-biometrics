@extends('layouts.app')

@section('title', 'Device Activity - ZCHPC Access Control')
@section('header', 'Device Activity Log')

@push('styles')
    <link rel="stylesheet" href="/css/devices.css">
@endpush

@section('content')
    <div class="device-toolbar">
        <p style="margin:0;color:var(--zchpc-text-muted);font-size:.9rem;">
            Who refreshed, reconnected, diagnosed or changed monitoring settings for each device, and the recorded outcome. Times in {{ config('attendance.timezone') }}.
        </p>
        <div class="device-actions">
            <a href="{{ route('devices.index') }}" class="btn btn-outline"><i class="fa-solid fa-tower-broadcast"></i> Devices</a>
            <a href="{{ route('devices.activity.export', request()->query()) }}" class="btn btn-primary"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
        </div>
    </div>

    <form method="GET" action="{{ route('devices.activity.index') }}" class="device-filters">
        <input type="date" name="from" value="{{ $filters['from']->format('Y-m-d') }}" aria-label="From date">
        <input type="date" name="to" value="{{ $filters['to']->format('Y-m-d') }}" aria-label="To date">
        <select name="terminal_id" aria-label="Device">
            <option value="">All devices</option>
            @foreach($terminals as $terminal)
                <option value="{{ $terminal->id }}" @selected($filters['terminal_id'] === $terminal->id)>{{ $terminal->name }}</option>
            @endforeach
        </select>
        <select name="action" aria-label="Action">
            <option value="">All actions</option>
            @foreach($actions as $action)
                <option value="{{ $action }}" @selected($filters['action'] === $action)>{{ str_replace('_', ' ', ucfirst($action)) }}</option>
            @endforeach
        </select>
        <select name="outcome" aria-label="Outcome">
            <option value="">All outcomes</option>
            @foreach(['success', 'failure', 'info'] as $outcome)
                <option value="{{ $outcome }}" @selected($filters['outcome'] === $outcome)>{{ ucfirst($outcome) }}</option>
            @endforeach
        </select>
        <input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Search detail, device or user" aria-label="Search activity">
        <button type="submit" class="btn btn-primary btn-xs"><i class="fa-solid fa-filter"></i> Apply</button>
        <a href="{{ route('devices.activity.index') }}" class="btn btn-outline btn-xs">Clear</a>
    </form>

    <div class="table-card">
        <div class="table-header">
            <h2>Recorded actions</h2>
            <span class="device-note">{{ $logs->total() }} record(s)</span>
        </div>
        <div class="table-wrapper">
            <table class="device-table">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Device</th>
                        <th>Action</th>
                        <th>Outcome</th>
                        <th>Administrator</th>
                        <th>Detail</th>
                        <th class="hide-md">Source</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr>
                            <td>{{ $log->created_at?->copy()->timezone(config('attendance.timezone'))->format('d M Y H:i:s') }}</td>
                            <td>{{ $log->terminal_name ?? 'All devices' }}</td>
                            <td>{{ $log->actionLabel() }}</td>
                            <td><span class="badge badge-{{ $log->tone() }}">{{ ucfirst($log->outcome) }}</span></td>
                            <td>{{ $log->user_name ?? 'System' }}</td>
                            <td><span style="font-size:.82rem;">{{ $log->message }}</span></td>
                            <td class="hide-md"><span class="device-meta">{{ $log->ip_address ?? 'Server task' }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="text-align:center;padding:3rem 1rem;color:var(--zchpc-text-muted);">
                                No device activity matches this filter.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div style="margin-top:1rem;">{{ $logs->links() }}</div>
@endsection
