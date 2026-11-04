@extends('layouts.app')

@section('title', 'Fingerprint Attendance Register - ZCHPC')
@section('header', 'Attendance Register')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/attendance.css') }}">
@endpush

@section('content')
<div class="attendance-intro">
    <div>
        <h2>Fingerprint entry register</h2>
        <p>Who unlocked the door, when they entered, and any recorded logout. Times are shown in {{ $timezone }}.</p>
    </div>
    <a class="btn btn-outline" href="{{ route('events.index') }}"><i class="fa-solid fa-arrows-rotate"></i> Collection status / import logs</a>
</div>

<div class="attendance-notice" role="note">
    <strong>The exit button does not identify a person.</strong>
    Login is the first successful fingerprint scan for each owner, terminal and day. Repeated scans remain available below.
    Logout stays “Not captured” until an operator records a known departure with a reason.
    These are imported door records; missing scans do not establish absence or hours worked.
</div>

@if(session('success'))
    <div class="attendance-success" role="status">{{ session('success') }}</div>
@endif
@if($errors->any())
    <div class="attendance-errors" role="alert">
        <strong>Please check the following:</strong>
        <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<div class="table-card attendance-filters">
    <form method="GET" action="{{ route('attendance.index') }}">
        <div class="attendance-filter-grid">
            <div><label for="register-from">From</label><input id="register-from" type="date" name="from" value="{{ $filters['from'] }}" required></div>
            <div><label for="register-to">To</label><input id="register-to" type="date" name="to" value="{{ $filters['to'] }}" required></div>
            <div>
                <label for="register-terminal">Terminal</label>
                <select id="register-terminal" name="terminal_id">
                    <option value="">All terminals</option>
                    @foreach($terminals as $terminal)
                        <option value="{{ $terminal->id }}" @selected($filters['terminal_id'] === $terminal->id)>{{ $terminal->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="register-group">Department / access group</label>
                <select id="register-group" name="access_group_id">
                    <option value="">All departments</option>
                    @foreach($accessGroups as $group)
                        <option value="{{ $group->id }}" @selected($filters['access_group_id'] === $group->id)>{{ $group->name }}</option>
                    @endforeach
                </select>
            </div>
            <div><label for="register-owner">Fingerprint owner / employee ID</label><input id="register-owner" type="search" name="search" value="{{ $filters['search'] }}" maxlength="128" placeholder="e.g. Prince or EMP-001"></div>
            <div>
                <label for="register-status">Logout status</label>
                <select id="register-status" name="status">
                    <option value="">All records</option>
                    <option value="missing" @selected($filters['status'] === 'missing')>Logout not captured</option>
                    <option value="recorded" @selected($filters['status'] === 'recorded')>Logout recorded manually</option>
                    <option value="review" @selected($filters['status'] === 'review')>New entry after logout</option>
                </select>
            </div>
        </div>
        <div class="attendance-filter-actions">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Show register</button>
            <a class="btn btn-outline" href="{{ route('attendance.index') }}">Today / reset</a>
        </div>
    </form>
    <div class="attendance-downloads">
        <span>Download all {{ number_format($entries->total()) }} matching rows:</span>
        <a class="btn btn-outline" href="{{ route('attendance.export.csv', $filters) }}"><i class="fa-solid fa-file-csv"></i> CSV / Excel</a>
        <a class="btn btn-outline" href="{{ route('attendance.export.pdf', $filters) }}"><i class="fa-solid fa-file-pdf"></i> PDF</a>
    </div>
</div>

<div class="attendance-summary">
    <div><span>Fingerprint owners</span><strong>{{ number_format($summary['owners']) }}</strong></div>
    <div><span>Successful fingerprint scans</span><strong>{{ number_format($summary['scans']) }}</strong></div>
    <div><span>Logout missing / needs review</span><strong>{{ number_format($summary['missing']) }}</strong></div>
</div>

<div class="table-card">
    <div class="table-header"><h2>Owners and entry / logout times</h2><span>{{ $entries->total() }} matching row(s)</span></div>
    <div class="table-wrapper">
        <table class="attendance-table">
            <thead><tr><th>Date</th><th>Fingerprint owner</th><th>Terminal</th><th>Login time</th><th>Logout time</th><th>Record details</th></tr></thead>
            <tbody>
            @forelse($entries as $row)
                <tr>
                    <td class="attendance-time">{{ \Carbon\CarbonImmutable::parse($row['date'])->format('d M Y') }}</td>
                    <td><strong>{{ $row['owner'] }}</strong><span class="attendance-subtext">Employee ID: {{ $row['employee_no'] }}</span><span class="attendance-subtext">{{ $row['department'] ?? 'No department recorded' }}</span></td>
                    <td>{{ $row['terminal_name'] }}</td>
                    <td class="attendance-time"><strong>{{ $row['first_in']->format('H:i:s') }}</strong><span class="attendance-subtext">Fingerprint</span></td>
                    <td class="attendance-time">
                        @if($row['logout'])
                            <strong>{{ $row['logout']->format('H:i:s') }}</strong>
                            @if($row['logout']->toDateString() !== $row['date'])<span class="attendance-subtext">{{ $row['logout']->format('d M Y') }}</span>@endif
                            <span class="attendance-subtext">Manual · {{ $row['checkout']->recorded_by_name }}</span>
                        @else
                            <strong class="attendance-unconfirmed">Not captured</strong><span class="attendance-subtext">Exit button is anonymous</span>
                        @endif
                    </td>
                    <td>
                        <span class="attendance-state attendance-state--{{ $row['status'] }}">{{ $register->statusLabel($row['status']) }}</span>
                        <details class="attendance-details">
                            <summary>View {{ $row['scan_count'] }} fingerprint scan(s)</summary>
                            <ul>@foreach($row['scans'] as $scan)<li>{{ $scan['at']->format('d M Y H:i:s') }}</li>@endforeach</ul>
                        </details>
                        @if($row['checkout_history']->isNotEmpty())
                            <details class="attendance-details">
                                <summary>Logout history ({{ $row['checkout_history']->count() }})</summary>
                                @foreach($row['checkout_history'] as $record)
                                    <div class="attendance-history">
                                        <strong>{{ $record->checked_out_at->setTimezone($timezone)->format('d M Y H:i:s') }}</strong>
                                        <p>{{ $record->reason }}</p>
                                        <span class="attendance-subtext">Recorded by {{ $record->recorded_by_name }} at {{ $record->created_at->setTimezone($timezone)->format('d M Y H:i:s') }}</span>
                                    </div>
                                @endforeach
                            </details>
                        @endif
                        <details class="attendance-details">
                            <summary>{{ $row['checkout'] ? 'Correct recorded logout' : 'Record known logout' }}</summary>
                            <form class="attendance-checkout" method="POST" action="{{ route('attendance.checkout', array_merge($filters, ['accessEvent' => $row['first_event_id']])) }}">
                                @csrf
                                <label for="logout-{{ $row['first_event_id'] }}">Logout for {{ $row['owner'] }} ({{ $timezone }})</label>
                                <input id="logout-{{ $row['first_event_id'] }}" type="datetime-local" step="1" name="checked_out_at" required min="{{ $row['last_scan']->format('Y-m-d\TH:i:s') }}">
                                <label for="reason-{{ $row['first_event_id'] }}">Reason / how departure was confirmed</label>
                                <textarea id="reason-{{ $row['first_event_id'] }}" name="reason" required minlength="5" maxlength="500" rows="2" placeholder="e.g. Departure confirmed by the security desk"></textarea>
                                <p class="attendance-subtext">Your name, this time and the reason will be retained in the history.</p>
                                <button class="btn btn-primary" type="submit">Save logout</button>
                            </form>
                        </details>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="attendance-empty">No successful fingerprint entries match these filters. Import the terminal’s access logs or choose another date range.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($entries->hasPages())
        <nav class="attendance-pagination" aria-label="Attendance register pages">
            <span>Showing {{ $entries->firstItem() }}–{{ $entries->lastItem() }} of {{ $entries->total() }}</span>
            <div>
                @if($entries->previousPageUrl())<a class="btn btn-outline" href="{{ $entries->previousPageUrl() }}">Previous</a>@endif
                <span>Page {{ $entries->currentPage() }} of {{ $entries->lastPage() }}</span>
                @if($entries->nextPageUrl())<a class="btn btn-outline" href="{{ $entries->nextPageUrl() }}">Next</a>@endif
            </div>
        </nav>
    @endif
</div>
@endsection

