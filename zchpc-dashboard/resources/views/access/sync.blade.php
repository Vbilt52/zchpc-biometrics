@extends('layouts.app')

@section('title', 'Device Synchronization - ZCHPC Access Control')
@section('header', 'Device Synchronization')

@section('content')
@include('access.partials.alerts')

<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start;margin-bottom:1rem;">
    <div>
        <h2 style="margin:0;">Controller synchronization</h2>
        <p class="hint" style="margin-top:.3rem;">
            A controller is only in sync when it has confirmed the current permissions. Queued writes are retried with
            backoff, and an offline controller never reports success.
        </p>
    </div>
    <a href="{{ route('access.index') }}" class="btn btn-outline"><i class="fa-solid fa-arrow-left"></i> Users &amp; Access</a>
</div>

<div class="access-stat-row">
    <div class="access-stat"><div class="value">{{ $stats['pending'] }}</div><div class="label">Writes pending</div></div>
    <div class="access-stat"><div class="value">{{ $stats['failing'] }}</div><div class="label">Have failed at least once</div></div>
    <div class="access-stat"><div class="value">{{ $stats['confirmed_today'] }}</div><div class="label">Confirmed today</div></div>
    <div class="access-stat"><div class="value">{{ $stats['failed_today'] }}</div><div class="label">Failed today</div></div>
    <div class="access-stat"><div class="value">{{ $stats['no_access'] }}</div><div class="label">People with no door access</div></div>
</div>

<!-- Synchronization State Legend -->
<div style="background:#ffffff;border-radius:0.75rem;padding:1rem 1.25rem;border:1px solid #e2e8f0;margin-bottom:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
    <div style="font-weight:700;font-size:0.85rem;color:#0f172a;margin-bottom:0.5rem;text-transform:uppercase;letter-spacing:0.04em;">
        Hardware Synchronization States
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:0.75rem;font-size:0.825rem;">
        <div style="display:flex;align-items:center;gap:0.4rem;">
            <span style="display:inline-block;padding:0.2rem 0.55rem;background:#ecfdf5;color:#047857;border-radius:4px;font-weight:700;border:1px solid #a7f3d0;">Synced</span>
            <span style="color:#64748b;">Confirmed by terminal readback</span>
        </div>
        <div style="display:flex;align-items:center;gap:0.4rem;">
            <span style="display:inline-block;padding:0.2rem 0.55rem;background:#fef3c7;color:#92400e;border-radius:4px;font-weight:700;border:1px solid #fde68a;">Pending</span>
            <span style="color:#64748b;">Queued for hardware dispatch</span>
        </div>
        <div style="display:flex;align-items:center;gap:0.4rem;">
            <span style="display:inline-block;padding:0.2rem 0.55rem;background:#fef2f2;color:#991b1b;border-radius:4px;font-weight:700;border:1px solid #fecaca;">Offline</span>
            <span style="color:#64748b;">Device disconnected; retrying</span>
        </div>
        <div style="display:flex;align-items:center;gap:0.4rem;">
            <span style="display:inline-block;padding:0.2rem 0.55rem;background:#fee2e2;color:#b91c1c;border-radius:4px;font-weight:700;border:1px solid #fca5a5;">Failed</span>
            <span style="color:#64748b;">Terminal rejected payload</span>
        </div>
        <div style="display:flex;align-items:center;gap:0.4rem;">
            <span style="display:inline-block;padding:0.2rem 0.55rem;background:#f1f5f9;color:#475569;border-radius:4px;font-weight:700;border:1px solid #cbd5e1;">Unsupported</span>
            <span style="color:#64748b;">API unsupported on reader</span>
        </div>
        <div style="display:flex;align-items:center;gap:0.4rem;">
            <span style="display:inline-block;padding:0.2rem 0.55rem;background:#eff6ff;color:#1e40af;border-radius:4px;font-weight:700;border:1px solid #bfdbfe;">Verification Required</span>
            <span style="color:#64748b;">Written; awaiting confirmation</span>
        </div>
    </div>
</div>

<div class="table-card" style="padding:1.25rem;margin-bottom:1.25rem;">
    <h3 style="margin:0 0 .65rem;font-size:.95rem;">Synchronization actions</h3>
    @can('access.manage')
        <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
            <form method="POST" action="{{ route('access.sync.store') }}" style="margin:0;"
                  onsubmit="return confirm('Queue every person with controller assignments for synchronization?')">
                @csrf
                <input type="hidden" name="scope" value="all">
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-rotate"></i> Synchronize all users</button>
            </form>

            <form method="POST" action="{{ route('access.sync.store') }}" style="margin:0;"
                  onsubmit="return confirm('Retry every pending controller write now?')">
                @csrf
                <input type="hidden" name="scope" value="pending">
                <button class="btn btn-outline" type="submit"><i class="fa-solid fa-arrows-rotate"></i> Retry pending ({{ $stats['pending'] }})</button>
            </form>
        </div>

        <details style="margin-top:1rem;">
            <summary style="cursor:pointer;font-size:.84rem;font-weight:600;color:var(--zchpc-primary);">Synchronize selected people</summary>
            <form method="POST" action="{{ route('access.sync.store') }}" style="margin-top:.75rem;">
                @csrf
                <input type="hidden" name="scope" value="selected">
                <label class="form-label" for="ids">People</label>
                <select class="form-select" id="ids" name="ids[]" multiple size="8" style="max-width:520px;">
                    @foreach($people as $person)
                        <option value="{{ $person->id }}">{{ $person->name }} · {{ $person->employee_no }}</option>
                    @endforeach
                </select>
                <p class="hint">Use Ctrl (Windows) or Cmd (macOS) to select more than one person.</p>
                <button class="btn btn-primary" type="submit">Queue selected</button>
            </form>
        </details>

        <p class="hint" style="margin-top:.8rem;">
            Queued work is processed by the queue worker (<code>php artisan queue:work</code>). The scheduler retries
            pending writes every minute. If no worker is running, writes stay pending.
        </p>
    @else
        <p class="hint" style="margin:0;">You can view synchronization state. An administrator can retry writes.</p>
    @endcan
</div>

<div class="table-card" style="margin-bottom:1.25rem;">
    <div class="table-header"><h3 style="margin:0;font-size:.95rem;">Pending controller writes</h3><span class="hint">Oldest first</span></div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Person</th><th>Controller</th><th>Operation</th><th>Attempts</th><th>Last error</th><th>Next attempt</th></tr></thead>
            <tbody>
            @forelse($pending as $sync)
                <tr>
                    <td>
                        @if($sync->personnel)
                            <a href="{{ route('access.users.show', $sync->personnel) }}" style="font-weight:600;color:var(--zchpc-primary);text-decoration:none;">{{ $sync->personnel->name }}</a>
                            <div class="hint"><code>{{ $sync->personnel->employee_no }}</code></div>
                        @else
                            <span class="hint">Removed person</span>
                        @endif
                    </td>
                    <td>
                        {{ $sync->terminal?->name ?? 'Removed controller' }}
                        <div class="hint">{{ $sync->terminal?->statusLabel() ?? '—' }}</div>
                    </td>
                    <td><span class="badge badge-{{ $sync->operation === 'revoke' ? 'warning' : 'primary' }}">{{ ucfirst($sync->operation) }}</span></td>
                    <td>{{ $sync->attempts }}</td>
                    <td class="hint" style="max-width:320px;">{{ $sync->last_error ?: '—' }}</td>
                    <td class="hint">{{ $sync->next_attempt_at?->diffForHumans() ?? 'Now' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;padding:1.5rem;color:var(--zchpc-text-muted);">Every controller write is confirmed.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="table-card">
    <div class="table-header"><h3 style="margin:0;font-size:.95rem;">Synchronization history</h3><span class="hint">Last 30 attempts</span></div>
    <div class="table-wrapper">
        <table>
            <thead><tr><th>Time</th><th>Person</th><th>Controller</th><th>Operation</th><th>Result</th><th>Detail</th></tr></thead>
            <tbody>
            @forelse($events as $event)
                <tr>
                    <td class="hint">{{ $event->created_at?->format('d M Y H:i:s') }}</td>
                    <td>{{ $event->person_name ?? '—' }} <span class="hint"><code>{{ $event->employee_no }}</code></span></td>
                    <td>{{ $event->terminal_name }}</td>
                    <td>{{ $event->operationLabel() }}</td>
                    <td><span class="badge badge-{{ $event->tone() === 'muted' ? 'muted' : $event->tone() }}">{{ $event->statusLabel() }}</span></td>
                    <td class="hint" style="max-width:340px;">{{ $event->message ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;padding:1.5rem;color:var(--zchpc-text-muted);">No synchronization attempts recorded yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
