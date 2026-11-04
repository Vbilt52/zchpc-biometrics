@extends('layouts.app')
@section('title', 'Edit Person - ZCHPC')
@section('header', 'Edit Person')
@section('content')
<div style="max-width:1000px;">
@if($errors->any())<div role="alert" style="padding:1rem;background:#fef2f2;color:#991b1b;margin-bottom:1rem;"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if(session('error'))<p role="alert">{{ session('error') }}</p>@endif
<form method="POST" action="{{ route('personnel.update', $personnel) }}">@csrf @method('PUT')
@include('personnel.form-fields')
<div style="display:flex;gap:1rem;justify-content:flex-end;"><a href="{{ route('personnel.show', $personnel) }}" class="btn btn-outline">Cancel</a><button class="btn btn-primary">Save and update devices</button></div>
</form>
<p class="form-help">Saving pushes changes to selected devices immediately. Device assignments that cannot be confirmed right away are queued and retried automatically. Once confirmed, enroll fingerprints from the person&rsquo;s detail page.</p>
</div>
@endsection
