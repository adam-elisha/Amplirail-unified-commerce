@extends('layouts.auth')
@section('title', 'Sign in')
@section('card')
  <h1>Sign in</h1>
  <label>Portal</label>
  <div class="portal-tabs" role="tablist">
    <a href="{{ route('admin.login') }}" class="{{ $portal === 'admin' ? 'active' : '' }}" role="tab" aria-selected="{{ $portal === 'admin' ? 'true' : 'false' }}">Admin</a>
    <a href="{{ route('merchant.login') }}" class="{{ $portal === 'merchant' ? 'active' : '' }}" role="tab" aria-selected="{{ $portal === 'merchant' ? 'true' : 'false' }}">Merchant</a>
  </div>
  <p class="muted small">
    @if ($portal === 'admin') eDataPay staff: merchants, payments, risk, finance, support and management. @else Merchant owners and staff. @endif
  </p>
  <form method="post" action="{{ route($portal.'.login.submit') }}">
    @csrf
    <label for="email">{{ $portal === 'admin' ? 'Work email' : 'Email' }}</label>
    <input id="email" name="email" type="email" autocomplete="username" required value="{{ old('email') }}">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <div class="alert info small full">
      @if ($portal === 'admin') Development mode: the second sign-in step is turned off for now. @else We will email you an 8-digit code after your password. @endif
    </div>
    <button class="btn full" type="submit">Continue to {{ $portal === 'admin' ? 'Admin' : 'Merchant portal' }}</button>
  </form>
  <p class="small muted">By signing in you agree to the <a href="{{ config('amplirail.legal.terms') }}" data-legal-popup>Terms of service</a> and <a href="{{ config('amplirail.legal.privacy') }}" data-legal-popup>Privacy policy</a>. Every sign-in is logged.</p>
@endsection
