@extends('layouts.app')

@section('title', 'Add New User - Details')
@section('header', 'Add New User')

@section('content')
@include('access.partials.alerts')
@include('access.partials.steps', ['current' => 1])

<div class="table-card" style="padding:1.5rem;max-width:980px;">
    <div style="margin-bottom:1.25rem;">
        <h2 style="margin:0;">Step 1 · Person details</h2>
        <p class="hint" style="margin-top:.3rem;">
            This creates a draft. Nothing is written to a controller and no door access is granted until you review and confirm.
        </p>
    </div>

    <form method="POST" action="{{ route('access.users.create.store') }}" enctype="multipart/form-data">
        @csrf
        @include('access.partials.fields', ['personnel' => null, 'departments' => $departments, 'employeeIdLocked' => false])

        <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-top:1.5rem;">
            <a href="{{ route('access.index') }}" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn btn-primary">Continue to device selection <i class="fa-solid fa-arrow-right"></i></button>
        </div>
    </form>
</div>
@endsection
