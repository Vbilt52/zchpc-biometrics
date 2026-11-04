@extends('layouts.app')
@section('title', 'Add Person - ZCHPC')
@section('header', 'Add Person')
@section('content')
<div style="max-width:1000px;">
@if($errors->any())<div role="alert" style="padding:1rem;background:#fef2f2;color:#991b1b;margin-bottom:1rem;"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if(session('error'))<p role="alert">{{ session('error') }}</p>@endif
<form method="POST" action="{{ route('personnel.store') }}">@csrf 
@include('personnel.form-fields')
<div style="display:flex;gap:1rem;justify-content:flex-end;"><a href="{{ route('personnel.index') }}" class="btn btn-outline">Cancel</a><button class="btn btn-primary">Save and update devices</button></div>
</form>
<p class="form-help">Saving pushes this person's profile to selected devices immediately and opens their profile so you can enroll fingerprints. Device assignments that cannot be confirmed immediately are queued and retried automatically.</p>
</div>
@endsection
