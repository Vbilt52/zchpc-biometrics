@extends('layouts.app')

@section('title', 'Edit Access Group')
@section('header', 'Edit Access Group')

@section('content')
<div class="table-card" style="max-width:620px;padding:1.5rem;">
    <form action="{{ route('access-groups.update', $accessGroup) }}" method="POST">
        @csrf @method('PUT')
        <label class="form-label" for="name">Access Group Name</label>
        <input class="form-input" id="name" name="name" value="{{ old('name', $accessGroup->name) }}" maxlength="64" required autofocus>
        @error('name')<p style="color:var(--danger);">{{ $message }}</p>@enderror
        @include('access-groups._doors', ['selectedDoorIds' => array_map('intval', old('door_ids', $selectedDoorIds))])
        @error('door_ids')<p style="color:var(--danger);">{{ $message }}</p>@enderror
        <div style="display:flex;justify-content:flex-end;gap:.75rem;margin-top:1.5rem;">
            <a href="{{ route('access-groups.index') }}" class="btn btn-outline">Cancel</a>
            <button class="btn btn-primary">Save Changes</button>
        </div>
    </form>
</div>
@endsection
