@extends('layouts.app')

@section('title', 'Access Matrix - ZCHPC Access Control')
@section('header', 'Access Matrix')

@section('content')
@include('access.partials.alerts')

<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start;margin-bottom:1rem;">
    <div>
        <h2 style="margin:0;">Who can open which door</h2>
        <p class="hint" style="margin-top:.3rem;">
            <strong>Allowed</strong> means the controller confirmed the permission; <strong>Pending</strong> means the
            permission is saved but the controller has not confirmed it yet.
        </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <a href="{{ route('access.matrix.export', request()->query()) }}" class="btn btn-outline"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
        <a href="{{ route('access.index') }}" class="btn btn-outline"><i class="fa-solid fa-users"></i> Users &amp; Access</a>
    </div>
</div>

<div class="table-card" style="padding:1rem 1.25rem;margin-bottom:1rem;">
    <form method="GET" action="{{ route('access.matrix.index') }}" style="display:flex;gap:.45rem;flex-wrap:wrap;align-items:end;">
        <div>
            <label class="form-label" for="search">Person</label>
            <input class="form-input" id="search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Name or employee ID" style="width:210px;">
        </div>
        <div>
            <label class="form-label" for="department">Department</label>
            <select class="form-select" id="department" name="department" style="width:auto;">
                <option value="">All departments</option>
                @foreach($departments as $department)
                    <option value="{{ $department->id }}" @selected((string) ($filters['department'] ?? '') === (string) $department->id)>{{ $department->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="door">Door</label>
            <select class="form-select" id="door" name="door" style="width:auto;">
                <option value="">Every door</option>
                @foreach($allDoors as $door)
                    <option value="{{ $door->id }}" @selected((string) ($filters['door'] ?? '') === (string) $door->id)>{{ $door->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="building">Building</label>
            <select class="form-select" id="building" name="building" style="width:auto;">
                <option value="">All buildings</option>
                @foreach($buildings as $building)
                    <option value="{{ $building }}" @selected(($filters['building'] ?? '') === $building)>{{ $building }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label" for="state">State</label>
            <select class="form-select" id="state" name="state" style="width:auto;">
                <option value="">Any</option>
                <option value="active" @selected(($filters['state'] ?? '') === 'active')>Active only</option>
                <option value="pending" @selected(($filters['state'] ?? '') === 'pending')>Pending controller writes</option>
                <option value="no_access" @selected(($filters['state'] ?? '') === 'no_access')>No door access</option>
            </select>
        </div>
        <button class="btn btn-primary" type="submit">Filter</button>
        @if(! empty($filters))
            <a href="{{ route('access.matrix.index') }}" class="btn btn-outline">Clear</a>
        @endif
    </form>
</div>

@if($truncated)
    <div class="notice notice-warning">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>The matrix shows the first 60 doors. Filter by building or door to see a narrower view.</div>
    </div>
@endif

<div class="table-card">
    <div class="table-header" style="flex-wrap:wrap;gap:.5rem;">
        <h3 style="margin:0;font-size:.95rem;">{{ $people->total() }} people</h3>
        <div style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;">
            <span class="matrix-cell granted">Allowed</span>
            <span class="matrix-cell pending">Awaiting controller</span>
            <span class="matrix-cell unverified">Not verified</span>
            <span class="matrix-cell suspended">Suspended</span>
            <span class="matrix-cell revoked">Revoked</span>
            <span class="matrix-cell expired">Expired</span>
            <span class="matrix-cell none">Denied</span>
        </div>
    </div>

    @if($columns->isEmpty())
        <div style="padding:2rem;text-align:center;color:var(--zchpc-text-muted);">
            No doors are registered yet.
            <a href="{{ route('access.doors.index') }}" style="color:var(--zchpc-primary);font-weight:600;">Register a door</a> to build the matrix.
        </div>
    @else
        <div class="matrix-wrapper">
            <table class="matrix-table">
                <thead>
                    <tr>
                        <th style="min-width:180px;">Person</th>
                        <th style="min-width:120px;">Department</th>
                        <th>Access</th>
                        @foreach($columns as $door)
                            <th>
                                {{ $door->name }}
                                <div style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--zchpc-text-muted);">
                                    {{ $door->areaLabel() }}
                                </div>
                                <div style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--zchpc-text-muted);">
                                    {{ $doorCounts[$door->id] ?? 0 }} authorised
                                </div>
                            </th>
                        @endforeach
                        <th style="min-width:110px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($people as $row)
                    @php $person = $row['person']; @endphp
                    <tr>
                        <td>
                            <a href="{{ route('access.users.show', $person) }}" style="font-weight:600;color:var(--zchpc-primary);text-decoration:none;">{{ $person->name }}</a>
                            <div class="hint"><code>{{ $person->employee_no }}</code></div>
                        </td>
                        <td class="hint">{{ $person->accessGroup?->name ?? $person->department ?? '—' }}</td>
                        <td>
                            <span class="badge badge-{{ $person->accessStateTone() === 'muted' ? 'muted' : $person->accessStateTone() }}">{{ $person->accessStateLabel() }}</span>
                            @if($row['pending'] > 0)
                                <div class="hint">{{ $row['pending'] }} pending</div>
                            @endif
                        </td>
                        @foreach($columns as $door)
                            @php $state = $row['cells']->get($door->id, 'none'); @endphp
                            <td>
                                @switch($state)
                                    @case('granted')
                                        <span class="matrix-cell granted"><i class="fa-solid fa-check"></i> Allowed</span>
                                        @break
                                    @case('pending')
                                    @case('not_deployed')
                                        <span class="matrix-cell pending"><i class="fa-solid fa-hourglass-half"></i> Pending</span>
                                        @break
                                    @case('unverified')
                                        <span class="matrix-cell unverified">Not verified</span>
                                        @break
                                    @case('suspended')
                                        <span class="matrix-cell suspended">Suspended</span>
                                        @break
                                    @case('revoked')
                                        <span class="matrix-cell revoked">Revoked</span>
                                        @break
                                    @case('expired')
                                        <span class="matrix-cell expired">Expired</span>
                                        @break
                                    @default
                                        <span class="matrix-cell none">Denied</span>
                                @endswitch
                            </td>
                        @endforeach
                        <td>
                            <a href="{{ route('access.users.show', $person) }}" class="btn btn-outline" style="padding:.25rem .5rem;font-size:.72rem;">Manage</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $columns->count() + 4 }}" style="text-align:center;padding:2rem;color:var(--zchpc-text-muted);">No people match these filters.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @include('access.partials.pagination', ['paginator' => $people])
    @endif
</div>
@endsection
