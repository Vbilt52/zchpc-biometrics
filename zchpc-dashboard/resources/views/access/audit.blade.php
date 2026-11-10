@extends('layouts.app')

@section('title', 'Access Audit Logs - ZCHPC')
@section('header', 'Access Audit Logs')

@section('content')
@include('access.partials.alerts')

<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start;margin-bottom:1rem;">
    <div>
        <h2 style="margin:0;">Permission and enrollment history</h2>
        <p class="hint" style="margin-top:.3rem;">
            Append-only record of identity, door-permission, enrollment and deletion actions. Biometric templates are never logged.
        </p>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <a href="{{ route('access.audit.download', request()->query()) }}"
           class="btn btn-outline"
           title="Download visible logs as CSV">
            <i class="fa-solid fa-download"></i> Download CSV
        </a>
        <a href="{{ route('access.index') }}" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> Users &amp; Access</a>
    </div>
</div>

<div class="table-card" style="padding:1rem 1.25rem;margin-bottom:1rem;">
    <form method="GET" action="{{ route('access.audit.index') }}" style="display:flex;gap:.45rem;flex-wrap:wrap;align-items:end;">
        <div>
            <label class="form-label" for="search">Search</label>
            <input class="form-input" id="search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Person, actor or detail" style="width:210px;">
        </div>
        <div>
            <label class="form-label" for="action">Action</label>
            <select class="form-select" id="action" name="action" style="width:auto;">
                <option value="">Any action</option>
                @foreach($actions as $action)
                    <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ str_replace('_', ' ', ucfirst($action)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="outcome">Outcome</label>
            <select class="form-select" id="outcome" name="outcome" style="width:auto;">
                <option value="">Any outcome</option>
                @foreach(['success', 'failure', 'warning', 'info'] as $outcome)
                    <option value="{{ $outcome }}" @selected(($filters['outcome'] ?? '') === $outcome)>{{ ucfirst($outcome) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="from">From</label>
            <input class="form-input" id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}">
        </div>
        <div>
            <label class="form-label" for="to">To</label>
            <input class="form-input" id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}">
        </div>
        <button class="btn btn-primary" type="submit">Filter</button>
        @if(! empty($filters))
            <a href="{{ route('access.audit.index') }}" class="btn btn-outline">Clear</a>
        @endif
    </form>
</div>

<div class="table-card">
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Subject</th><th>Outcome</th><th>Detail</th><th>Address</th></tr></thead>
            <tbody>
            @forelse($logs as $log)
                <tr>
                    <td class="hint" style="white-space:nowrap;">{{ $log->created_at?->format('d M Y H:i:s') }}</td>
                    <td>{{ $log->user_name }}</td>
                    <td style="font-weight:600;">{{ $log->actionLabel() }}</td>
                    <td>
                        {{ $log->subject_label ?: '—' }}
                        @if($log->terminal)
                            <div class="hint">{{ $log->terminal->name }}</div>
                        @endif
                    </td>
                    <td><span class="badge badge-{{ $log->tone() === 'muted' ? 'muted' : $log->tone() }}">{{ $log->outcome }}</span></td>
                    <td style="max-width:420px;font-size:.8rem;">{{ $log->description ?: '—' }}</td>
                    <td class="hint">{{ $log->ip_address ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--zchpc-text-muted);">No audit entries match these filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('access.partials.pagination', ['paginator' => $logs])
</div>
@endsection
