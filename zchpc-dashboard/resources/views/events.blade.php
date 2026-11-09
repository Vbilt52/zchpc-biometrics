@extends('layouts.app')

@section('title', $filters['outcome'] === 'denied' ? 'Failed Attempts - ZCHPC Access Control' : 'Access Logs - ZCHPC Access Control')
@section('header', $filters['outcome'] === 'denied' ? 'Failed Access Attempts' : 'Access Event Logs')

@section('content')
<style>
    .log-actions { display:flex; gap:.75rem; flex-wrap:wrap; align-items:center; border-top:1px solid var(--zchpc-border); margin-top:1.1rem; padding-top:1.1rem; }
    .log-action-form { display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; }
    .log-action-form select { min-width:190px; padding:.6rem .7rem; border:1px solid var(--zchpc-border); border-radius:var(--radius-md); background:#fff; }
    .log-actions .btn { min-height:2.55rem; white-space:nowrap; justify-content:center; box-shadow:none; }
    .btn-import-all { color:var(--zchpc-primary); border-color:rgba(0,74,134,.35); background:#fff; }
    .btn-import-all:hover { color:#fff; background:var(--zchpc-primary); border-color:var(--zchpc-primary); }
    .log-action-note { flex:1 1 270px; font-size:.8rem; color:var(--zchpc-text-muted); }
    .log-exports { display:flex; gap:.5rem; flex-wrap:wrap; }
    .event-pagination { display:flex; justify-content:space-between; align-items:center; gap:.75rem; flex-wrap:wrap; padding:1rem 1.25rem; border-top:1px solid var(--zchpc-border); }
    .event-pagination__summary { color:var(--zchpc-text-muted); font-size:.82rem; }
    .event-pagination__controls { display:flex; gap:.45rem; align-items:center; }
    .event-pagination__controls .btn { min-width:6.4rem; justify-content:center; }
    @media (max-width: 720px) { .log-actions, .log-action-form, .log-exports { width:100%; } .log-action-form select, .log-actions .btn { flex:1 1 auto; } }
</style>
@if(session('success'))
    <div style="background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.35);border-radius:var(--radius-md);padding:.875rem 1.25rem;margin-bottom:1.25rem;color:#065f46;">
        <i class="fa-solid fa-circle-check"></i> {{ session('success') }}
    </div>
@endif
@if(session('error'))
    <div style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:var(--radius-md);padding:.875rem 1.25rem;margin-bottom:1.25rem;color:#7f1d1d;">
        <i class="fa-solid fa-triangle-exclamation"></i> {{ session('error') }}
    </div>
@endif

@include('events.sync-status')

@if($filters['outcome'] === 'denied')
<p style="padding:1rem;background:#fff7ed;margin-bottom:1rem;">Failed attempts show the terminal and recorded time even when no person is identified. Names are shown only when supplied by the event or linked employee ID. Only attempts recorded and collected from the device can appear here.</p>
@endif

<div class="table-card" style="margin-bottom:1.25rem;">
    <div class="table-header">
        <div>
            <h2>Find and Import Access Logs</h2>
            <p style="margin:.25rem 0 0;color:var(--zchpc-text-muted);font-size:.82rem;">Search collected records or import an older date range. Date filters and event times use {{ config('attendance.timezone') }}.</p>
        </div>
    </div>
    <div style="padding:1.25rem;">
        <form id="event-filter-form" method="GET" action="{{ route('events.index') }}" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.85rem;align-items:end;">
            <div><label for="event-outcome" style="display:block;font-size:.78rem;font-weight:600;margin-bottom:.35rem;">RESULT</label>
                <select id="event-outcome" name="outcome" style="width:100%;padding:.6rem;border:1px solid var(--zchpc-border);border-radius:.5rem;">
                    <option value="all" @selected($filters['outcome'] === 'all')>All events</option>
                    <option value="denied" @selected($filters['outcome'] === 'denied')>Failed attempts only</option>
                </select>
            </div>
            <div>
                <label style="font-size:.78rem;font-weight:600;color:var(--zchpc-text-muted);display:block;margin-bottom:.35rem;">TERMINAL</label>
                <select name="terminal_id" style="width:100%;padding:.6rem .7rem;border:1px solid var(--zchpc-border);border-radius:var(--radius-md);">
                    <option value="">All terminals</option>
                    @foreach($terminals as $terminal)
                        <option value="{{ $terminal->id }}" @selected($filters['terminal_id'] === $terminal->id)>{{ $terminal->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="font-size:.78rem;font-weight:600;color:var(--zchpc-text-muted);display:block;margin-bottom:.35rem;">DOOR</label>
                <select name="door_id" style="width:100%;padding:.6rem .7rem;border:1px solid var(--zchpc-border);border-radius:var(--radius-md);">
                    <option value="">All doors</option>
                    @foreach($doors as $door)
                        <option value="{{ $door->id }}" @selected(($filters['door_id'] ?? null) === $door->id)>
                            {{ $door->name }} ({{ $door->terminal?->name }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="font-size:.78rem;font-weight:600;color:var(--zchpc-text-muted);display:block;margin-bottom:.35rem;">PERSON</label>
                <select name="personnel_id" style="width:100%;padding:.6rem .7rem;border:1px solid var(--zchpc-border);border-radius:var(--radius-md);">
                    <option value="">All people</option>
                    @foreach($personnel as $person)
                        <option value="{{ $person->id }}" @selected($filters['personnel_id'] === $person->id)>{{ $person->name }} ({{ $person->employee_no }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="font-size:.78rem;font-weight:600;color:var(--zchpc-text-muted);display:block;margin-bottom:.35rem;">NAME OR EMPLOYEE ID</label>
                <input type="search" name="search" value="{{ $filters['search'] }}" placeholder="e.g. Prince or EMP-001" style="width:100%;padding:.6rem .7rem;border:1px solid var(--zchpc-border);border-radius:var(--radius-md);">
            </div>
            <div>
                <label style="font-size:.78rem;font-weight:600;color:var(--zchpc-text-muted);display:block;margin-bottom:.35rem;">FROM</label>
                <input id="event-from" type="datetime-local" name="from" value="{{ $filters['from']->format('Y-m-d\TH:i') }}" style="width:100%;padding:.6rem .7rem;border:1px solid var(--zchpc-border);border-radius:var(--radius-md);">
            </div>
            <div>
                <label style="font-size:.78rem;font-weight:600;color:var(--zchpc-text-muted);display:block;margin-bottom:.35rem;">TO</label>
                <input id="event-to" type="datetime-local" name="to" value="{{ $filters['to']->format('Y-m-d\TH:i') }}" style="width:100%;padding:.6rem .7rem;border:1px solid var(--zchpc-border);border-radius:var(--radius-md);">
            </div>
            <div style="display:flex;gap:.5rem;">
                <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                <a href="{{ route('events.index') }}" class="btn btn-outline" title="Clear filters"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </form>

        <div class="log-actions">
            <form method="POST" action="{{ route('events.import') }}" class="log-action-form js-import-form">
                @csrf
                <select name="import_terminal_id" required style="min-width:180px;padding:.6rem .7rem;border:1px solid var(--zchpc-border);border-radius:var(--radius-md);">
                    <option value="">Select terminal to import</option>
                    @foreach($terminals as $terminal)
                        <option value="{{ $terminal->id }}" @selected($filters['terminal_id'] === $terminal->id)>{{ $terminal->name }}</option>
                    @endforeach
                </select>
                <input class="js-import-from" type="hidden" name="import_from" value="{{ $filters['from']->format('Y-m-d\TH:i') }}">
                <input class="js-import-to" type="hidden" name="import_to" value="{{ $filters['to']->format('Y-m-d\TH:i') }}">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-download"></i> Import selected
                </button>
            </form>
            <form method="POST" action="{{ route('events.import.all') }}" class="log-action-form js-import-form">
                @csrf
                <input class="js-import-from" type="hidden" name="import_from" value="{{ $filters['from']->format('Y-m-d\TH:i') }}">
                <input class="js-import-to" type="hidden" name="import_to" value="{{ $filters['to']->format('Y-m-d\TH:i') }}">
                <button type="submit" class="btn btn-outline btn-import-all"><i class="fa-solid fa-arrows-rotate"></i> Import all terminals</button>
            </form>
            <span class="log-action-note">Imports every person for the selected date range. Terminals that cannot be reached are reported separately, without stopping the others.</span>
            <div class="log-exports">
                <a href="{{ route('events.export.pdf', request()->query()) }}" class="btn btn-outline" style="color:#b91c1c;border-color:#b91c1c;"><i class="fa-solid fa-file-pdf"></i> PDF</a>
                <a href="{{ route('events.export.word', request()->query()) }}" class="btn btn-outline"><i class="fa-solid fa-file-word"></i> Document</a>
            </div>
        </div>
    </div>
</div>

<div class="table-card animate-fade-in">
    <div class="table-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h2>Imported Audit Records</h2>
        <span style="font-size:.8rem;color:var(--zchpc-text-muted);">{{ $events->total() }} matching record(s)</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Time</th><th>Terminal</th><th>Door</th><th>Person / Employee ID</th><th>Method</th><th>Result</th></tr></thead>
            <tbody>
                @forelse($events as $event)
                    @php
                        $outcome = $event->outcomeLabel();
                        $outcomeClass = match($outcome) { 'Granted' => 'badge-success', 'Denied' => 'badge-danger', 'Exit' => 'badge-info', default => 'badge-muted' };
                        $doorNo = $event->raw_payload['doorNo'] ?? null;
                        $doorObj = $doorNo && isset($doorsByTerminalDoor[$event->terminal_id.'_'.$doorNo]) ? $doorsByTerminalDoor[$event->terminal_id.'_'.$doorNo] : null;
                        $doorDisplay = $doorObj ? $doorObj->name : ($doorNo ? 'Door '.$doorNo : '—');
                    @endphp
                    <tr>
                        <td style="white-space:nowrap;color:var(--zchpc-text-muted);font-size:.84rem;">{{ app(\App\Services\AttendanceRegisterService::class)->eventTime($event)?->format('d M Y H:i:s') ?? 'Unknown time' }}</td>
                        <td style="font-weight:600;font-size:.85rem;">{{ $event->terminal?->name ?? 'Removed terminal' }}</td>
                        <td style="font-size:.85rem;color:var(--zchpc-text-main);font-weight:600;">
                            <span class="badge" style="background:rgba(12,112,162,0.09);color:#0c70a2;border:1px solid rgba(12,112,162,0.25);font-size:.8rem;padding:.2rem .55rem;">
                                <i class="fa-solid fa-door-closed" style="margin-right:4px;font-size:.75rem;"></i>{{ $doorDisplay }}
                            </span>
                        </td>
                        <td><div style="font-weight:600;font-size:.88rem;">{{ $event->person_name ?: ($event->personnel?->name ?: 'Unknown person') }}</div><div style="font-family:monospace;font-size:.76rem;color:var(--zchpc-text-muted);">{{ $event->employee_no ? 'ID: '.$event->employee_no : 'No employee ID' }}</div></td>
                        <td><span style="font-size:.82rem;padding:.25rem .55rem;border-radius:var(--radius-md);background:rgba(0,74,134,.07);color:var(--zchpc-primary);">{{ $event->methodLabel() }}</span></td>
                        <td><span class="badge {{ $outcomeClass }}">{{ $outcome }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center;padding:3rem;color:var(--zchpc-text-muted);"><i class="fa-solid fa-clock-rotate-left" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.75rem;"></i>No imported records match the selected filters. Choose one terminal and import its logs first.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($events->hasPages())
        <nav class="event-pagination" aria-label="Access event pages">
            <span class="event-pagination__summary">Showing {{ $events->firstItem() }}–{{ $events->lastItem() }} of {{ $events->total() }} records</span>
            <div class="event-pagination__controls">
                @if($events->onFirstPage())
                    <span class="btn btn-outline" aria-disabled="true" style="opacity:.45;pointer-events:none;"><i class="fa-solid fa-chevron-left"></i> Previous</span>
                @else
                    <a class="btn btn-outline" href="{{ $events->previousPageUrl() }}"><i class="fa-solid fa-chevron-left"></i> Previous</a>
                @endif
                <span class="event-pagination__summary">Page {{ $events->currentPage() }} of {{ $events->lastPage() }}</span>
                @if($events->hasMorePages())
                    <a class="btn btn-outline" href="{{ $events->nextPageUrl() }}">Next <i class="fa-solid fa-chevron-right"></i></a>
                @else
                    <span class="btn btn-outline" aria-disabled="true" style="opacity:.45;pointer-events:none;">Next <i class="fa-solid fa-chevron-right"></i></span>
                @endif
            </div>
        </nav>
    @endif
</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('.js-import-form').forEach((form) => {
    form.addEventListener('submit', () => {
        form.querySelector('.js-import-from').value = document.getElementById('event-from').value;
        form.querySelector('.js-import-to').value = document.getElementById('event-to').value;
    });
});
</script>
@endpush


