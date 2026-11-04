@extends('layouts.app')

@section('title', 'Users & Access - ZCHPC Access Control')
@section('header', 'Users & Access')

@section('content')
@include('access.partials.alerts')

<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">
    <div>
        <h2 style="margin:0;">People and door permissions</h2>
        <p style="color:var(--zchpc-text-muted);margin:.3rem 0 0;font-size:.875rem;">
            Door access is granted per person. A controller is only considered in sync when it has confirmed the current permissions.
        </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
        <div class="dropdown" style="position:relative;display:inline-block;">
            <button class="btn btn-outline" id="exportDropdownBtn" type="button" onclick="toggleExportMenu(event)">
                <i class="fa-solid fa-download"></i> Download access list <i class="fa-solid fa-chevron-down" style="font-size:.72rem;margin-left:.25rem;"></i>
            </button>
            <div id="exportDropdownMenu" class="dropdown-menu" style="display:none;position:absolute;right:0;top:100%;margin-top:.35rem;background:#ffffff;border:1px solid var(--zchpc-border, #e2e8f0);border-radius:8px;box-shadow:0 10px 15px -3px rgba(0,0,0,0.1),0 4px 6px -2px rgba(0,0,0,0.05);z-index:50;min-width:235px;padding:.35rem 0;">
                <a href="{{ route('access.users.export.csv', request()->query()) }}" class="dropdown-item export-dropdown-item" style="display:flex;align-items:center;gap:.65rem;padding:.6rem 1rem;color:var(--zchpc-text, #1e293b);text-decoration:none;font-size:.875rem;transition:background .15s ease;">
                    <i class="fa-solid fa-file-csv" style="color:#0284c7;width:18px;font-size:1.1rem;"></i>
                    <div>
                        <div style="font-weight:600;">Download CSV (Excel)</div>
                        <div style="font-size:.75rem;color:var(--zchpc-text-muted);">Spreadsheet with all devices & doors</div>
                    </div>
                </a>
                <a href="{{ route('access.users.export.pdf', request()->query()) }}" class="dropdown-item export-dropdown-item" style="display:flex;align-items:center;gap:.65rem;padding:.6rem 1rem;color:var(--zchpc-text, #1e293b);text-decoration:none;font-size:.875rem;border-top:1px solid #f1f5f9;transition:background .15s ease;">
                    <i class="fa-solid fa-file-pdf" style="color:#dc2626;width:18px;font-size:1.1rem;"></i>
                    <div>
                        <div style="font-weight:600;">Download PDF Report</div>
                        <div style="font-size:.75rem;color:var(--zchpc-text-muted);">Official printable directory report</div>
                    </div>
                </a>
                <a href="{{ route('access.users.export.word', request()->query()) }}" class="dropdown-item export-dropdown-item" style="display:flex;align-items:center;gap:.65rem;padding:.6rem 1rem;color:var(--zchpc-text, #1e293b);text-decoration:none;font-size:.875rem;border-top:1px solid #f1f5f9;transition:background .15s ease;">
                    <i class="fa-solid fa-file-word" style="color:#2563eb;width:18px;font-size:1.1rem;"></i>
                    <div>
                        <div style="font-weight:600;">Download Word Doc</div>
                        <div style="font-size:.75rem;color:var(--zchpc-text-muted);">Editable document format</div>
                    </div>
                </a>
            </div>
        </div>
        <a href="{{ route('access.matrix.index') }}" class="btn btn-outline"><i class="fa-solid fa-table-cells"></i> Access matrix</a>
        <a href="{{ route('access.sync.index') }}" class="btn btn-outline"><i class="fa-solid fa-rotate"></i> Synchronization</a>
        @can('access.manage')
            <a href="{{ route('access.users.create') }}" class="btn btn-primary"><i class="fa-solid fa-user-plus"></i> Add New User</a>
        @endcan
    </div>
</div>

<div class="access-stat-row">
    <div class="access-stat"><div class="value">{{ $stats['people'] }}</div><div class="label">Registered people</div></div>
    <div class="access-stat"><div class="value">{{ $stats['fingerprints'] }}</div><div class="label">With a confirmed fingerprint</div></div>
    <div class="access-stat"><div class="value">{{ $stats['doors'] }}</div><div class="label">Doors in service</div></div>
    <div class="access-stat"><div class="value">{{ $stats['pending'] }}</div><div class="label">Controller writes pending</div></div>
    <div class="access-stat"><div class="value">{{ $stats['denied_today'] }}</div><div class="label">Denied attempts today</div></div>
</div>

@if($drafts->isNotEmpty())
    <div class="table-card" style="margin-bottom:1.25rem;">
        <div class="table-header">
            <h2>Unfinished onboarding</h2>
            <span class="badge badge-warning">{{ $drafts->count() }}</span>
        </div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Person</th><th>Employee ID</th><th>Department</th><th>Last changed</th><th style="width:220px;">Actions</th></tr></thead>
                <tbody>
                @foreach($drafts as $draft)
                    <tr>
                        <td style="font-weight:600;">{{ $draft->name }}</td>
                        <td><code>{{ $draft->employee_no }}</code></td>
                        <td>{{ $draft->accessGroup?->name ?? $draft->department ?? '—' }}</td>
                        <td class="hint">{{ $draft->updated_at?->diffForHumans() }}</td>
                        <td>
                            <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                                <a href="{{ route('access.users.show', $draft) }}" class="btn btn-outline" style="padding:.35rem .65rem;">Open</a>
                                @can('access.manage')
                                    <form method="POST" action="{{ route('access.users.resume', $draft) }}" style="margin:0;">
                                        @csrf
                                        <button class="btn btn-outline" style="padding:.35rem .65rem;">Resume</button>
                                    </form>
                                    <form method="POST" action="{{ route('access.users.discard', $draft) }}" style="margin:0;"
                                          onsubmit="return confirm('Discard this draft? Any device identity created for enrollment is removed first.')">
                                        @csrf @method('DELETE')
                                        <input type="hidden" name="confirm" value="1">
                                        <button class="btn btn-outline" style="padding:.35rem .65rem;color:var(--danger);">Discard</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="table-card">
    <div class="table-header" style="gap:1rem;flex-wrap:wrap;">
        <h2>All people</h2>
        <form method="GET" action="{{ route('access.index') }}" style="display:flex;gap:.45rem;flex-wrap:wrap;">
            <input class="form-input" type="search" name="search" value="{{ $filters['search'] }}" placeholder="Name, ID, email or phone" style="width:230px;">
            <select class="form-select" name="department" onchange="this.form.submit()" style="width:auto;">
                <option value="">All departments</option>
                @foreach($departments as $department)
                    <option value="{{ $department->id }}" @selected((string) $filters['department'] === (string) $department->id)>{{ $department->name }}</option>
                @endforeach
            </select>
            <select class="form-select" name="state" onchange="this.form.submit()" style="width:auto;">
                <option value="">Any access state</option>
                <option value="active" @selected($filters['state'] === 'active')>Active</option>
                <option value="suspended" @selected($filters['state'] === 'suspended')>Suspended</option>
                <option value="expired" @selected($filters['state'] === 'expired')>Expired</option>
                <option value="disabled" @selected($filters['state'] === 'disabled')>Disabled</option>
                <option value="blocked" @selected($filters['state'] === 'blocked')>Blocked</option>
                <option value="no_fingerprint" @selected($filters['state'] === 'no_fingerprint')>No fingerprint</option>
            </select>
            <select class="form-select" name="sync" onchange="this.form.submit()" style="width:auto;">
                <option value="">Any sync state</option>
                <option value="pending" @selected($filters['sync'] === 'pending')>Pending controller writes</option>
            </select>
            <button class="btn btn-outline" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
            @if($filters['search'] || $filters['department'] || $filters['state'] || $filters['sync'])
                <a href="{{ route('access.index') }}" class="btn btn-outline" title="Clear filters"><i class="fa-solid fa-xmark"></i></a>
            @endif
        </form>
    </div>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Person</th>
                    <th>Department</th>
                    <th>Fingerprint</th>
                    <th>Doors</th>
                    <th>Access</th>
                    <th>Controller sync</th>
                    <th>Last access</th>
                    <th style="width:90px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($people as $person)
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:.6rem;">
                            @if($person->photo_path)
                                <img src="{{ $person->photoUrl() }}" alt="" style="width:34px;height:34px;border-radius:50%;object-fit:cover;">
                            @else
                                <span style="width:34px;height:34px;border-radius:50%;display:grid;place-items:center;background:rgba(0,74,134,.1);color:var(--zchpc-primary);font-weight:700;font-size:.75rem;">{{ $person->initials() }}</span>
                            @endif
                            <div>
                                <div style="font-weight:600;">{{ $person->name }}</div>
                                <div style="font-size:.75rem;color:var(--zchpc-text-muted);"><code>{{ $person->employee_no }}</code></div>
                            </div>
                        </div>
                    </td>
                    <td>{{ $person->accessGroup?->name ?? $person->department ?? '—' }}</td>
                    <td>
                        @if($person->confirmed_fingerprints_count > 0)
                            <span class="badge badge-success"><i class="fa-solid fa-fingerprint"></i> {{ $person->confirmed_fingerprints_count }}</span>
                        @else
                            <span class="badge badge-muted">Not enrolled</span>
                        @endif
                    </td>
                    <td>
                        @if($person->granted_doors_count > 0)
                            {{ $person->granted_doors_count }}
                        @else
                            <span class="hint">None</span>
                        @endif
                    </td>
                    <td><span class="badge badge-{{ $person->accessStateTone() === 'muted' ? 'muted' : $person->accessStateTone() }}">{{ $person->accessStateLabel() }}</span></td>
                    <td>
                        @if($person->pending_sync_count > 0)
                            <span class="badge badge-warning"><i class="fa-solid fa-rotate"></i> {{ $person->pending_sync_count }} pending</span>
                        @elseif($person->isSuspended())
                            <span class="badge badge-danger">Access blocked</span>
                        @elseif($person->granted_doors_count === 0)
                            <span class="badge badge-muted">No access configured</span>
                        @elseif($person->terminals->contains(fn ($terminal) => $terminal->pivot->access_allowed && ! in_array((string) $terminal->pivot->sync_status, ['confirmed'], true)))
                            <span class="badge badge-warning">Unverified on controller</span>
                        @else
                            <span class="badge badge-success">Confirmed</span>
                        @endif
                    </td>
                    <td class="hint">
                        {{ $person->last_access_at ? \Illuminate\Support\Carbon::parse($person->last_access_at)->format('d M Y H:i') : '—' }}
                    </td>
                    <td><a href="{{ route('access.users.show', $person) }}" class="btn btn-outline" style="padding:.35rem .6rem;">View</a></td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--zchpc-text-muted);">No people match these filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @include('access.partials.pagination', ['paginator' => $people])
</div>

@push('styles')
<style>
    .export-dropdown-item:hover {
        background-color: #f1f5f9 !important;
    }
</style>
@endpush

@push('scripts')
<script>
    function toggleExportMenu(e) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        var menu = document.getElementById('exportDropdownMenu');
        if (!menu) return;
        menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
    }

    document.addEventListener('click', function(e) {
        var menu = document.getElementById('exportDropdownMenu');
        var btn = document.getElementById('exportDropdownBtn');
        if (menu && menu.style.display === 'block') {
            if (!menu.contains(e.target) && !btn.contains(e.target)) {
                menu.style.display = 'none';
            }
        }
    });
</script>
@endpush
@endsection

