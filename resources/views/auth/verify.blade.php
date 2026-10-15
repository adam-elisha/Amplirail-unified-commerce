@extends('layouts.auth')
@section('title', 'Verify')
@section('card')
  <h1>{{ $portal === 'admin' ? 'Authenticator code' : 'Check your email' }}</h1>
  <p class="muted">
    @if ($portal === 'admin') Enter the 6-digit code from your authenticator app. @else We sent an 8-digit code to your email. It expires in {{ config('amplirail.auth.otp_ttl_minutes') }} minutes. @endif
  </p>
  <form method="post" action="{{ route($portal.'.verify.submit') }}">
    @csrf
    <label for="code">Code</label>
    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="{{ $portal === 'admin' ? 6 : 8 }}" required autofocus>
    <button class="btn full" type="submit">Verify and sign in</button>
  </form>
  @if ($portal === 'merchant')
    <form method="post" action="{{ route('merchant.verify.resend') }}">@csrf<button class="btn secondary full" type="submit">Send a new code</button></form>
  @endif
  <p class="small"><a href="{{ route($portal.'.login') }}">Use a different account</a></p>
@endsection
