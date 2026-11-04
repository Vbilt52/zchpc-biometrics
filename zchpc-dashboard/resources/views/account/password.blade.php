@extends('layouts.app')
@section('title', 'Account settings - ZCHPC Access Control')
@section('header', 'Account Settings')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/auth.css') }}">
@endpush
@section('content')
<section class="table-card auth-card" style="width:min(560px,100%);">
    <h1>Change your password</h1>
    <p class="auth-description">Signed in as {{ auth()->user()->name }}. Choose at least 12 characters. You will sign in again after changing your password.</p>
    <form method="POST" action="{{ route('account.password.update') }}">
        @csrf
        @method('PUT')
        <label class="form-label" for="current-password">Current password</label>
        <input class="form-input" id="current-password" name="current_password" type="password" autocomplete="current-password" required>
        @error('current_password')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
        <label class="form-label" for="new-password">New password</label>
        <input class="form-input" id="new-password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required>
        @error('password')<p class="auth-error" role="alert">{{ $message }}</p>@enderror
        <label class="form-label" for="confirm-password">Confirm new password</label>
        <input class="form-input" id="confirm-password" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="128" required>
        <button class="btn btn-primary auth-submit" style="margin-top:1.5rem;">Save new password</button>
    </form>
</section>
@endsection
