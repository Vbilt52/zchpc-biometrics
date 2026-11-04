@extends('layouts.app')

@section('title', 'Access Groups - ZCHPC Access Control')
@section('header', 'Access Groups')

@section('content')
@if(session('success'))
<div class="alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
<div class="alert-error">{{ session('error') }}</div>
@endif

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;">
    <div>
        <h2 style="margin:0;">Departments and Access Groups</h2>
        <p style="color:var(--zchpc-text-muted);margin:.35rem 0 0;">Create groups here, then assign or change them on every person.</p>
    </div>
    <a href="{{ route('access-groups.create') }}" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Access Group</a>
</div>

<div class="table-card"><div class="table-wrapper"><table>
    <thead><tr><th>Name</th><th>People</th><th>Doors granted</th><th style="width:180px;">Actions</th></tr></thead>
    <tbody>
    @forelse($groups as $group)
        <tr>
            <td style="font-weight:600;">{{ $group->name }}</td>
            <td>{{ $group->personnel_count }}</td>
            <td>{{ $group->doors_count }}</td>
            <td style="display:flex;gap:.5rem;">
                <a href="{{ route('access-groups.edit', $group) }}" class="btn btn-outline">Edit</a>
                @if($group->personnel_count === 0)
                <form action="{{ route('access-groups.destroy', $group) }}" method="POST" onsubmit="return confirm('Delete this access group?')">
                    @csrf @method('DELETE')
                    <button class="btn btn-outline" style="color:var(--danger);">Delete</button>
                </form>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="4" style="text-align:center;padding:2rem;">No access groups have been created.</td></tr>
    @endforelse
    </tbody>
</table></div></div>
@endsection
