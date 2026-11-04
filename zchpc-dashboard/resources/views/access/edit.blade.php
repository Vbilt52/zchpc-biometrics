@extends('layouts.app')

@section('title', 'Edit ' . $personnel->name)
@section('header', 'Edit person')

@section('content')
@include('access.partials.alerts')

<div class="table-card" style="padding:1.5rem;max-width:980px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem;">
        <div>
            <h2 style="margin:0;">{{ $personnel->name }}</h2>
            <p class="hint" style="margin-top:.3rem;">
                Door permissions, suspension and deletion are managed from the person profile.
            </p>
        </div>
        <span class="badge badge-{{ $personnel->accessStateTone() === 'muted' ? 'muted' : $personnel->accessStateTone() }}">{{ $personnel->accessStateLabel() }}</span>
    </div>

    <form method="POST" action="{{ route('access.users.update', $personnel) }}" enctype="multipart/form-data">
        @csrf @method('PUT')
        @include('access.partials.fields', [
            'personnel' => $personnel,
            'departments' => $departments,
            'employeeIdLocked' => $personnel->fingerprintEnrollments()->exists() || $personnel->terminals()->exists(),
        ])

        <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-top:1.5rem;">
            <a href="{{ route('access.users.show', $personnel) }}" class="btn btn-outline">Cancel</a>
            <button class="btn btn-primary" type="submit">Save details</button>
        </div>
    </form>
</div>
@endsection
