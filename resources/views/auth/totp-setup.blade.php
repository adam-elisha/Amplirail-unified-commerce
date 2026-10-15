@extends('layouts.auth')
@section('title', 'Set up authenticator')
@section('card')
  <h1>Set up your authenticator</h1>
  <p class="muted">Admin accounts need an authenticator app (PCI DSS 8.4). Scan the code, then enter the 6 digits it shows.</p>
  <div class="qr">{!! $qr !!}</div>
  <p class="small muted">Can't scan? Enter this key manually:</p>
  <div class="secret">{{ trim(chunk_split($secret, 4, ' ')) }}</div>
  <form method="post" action="{{ route('admin.totp.submit') }}">
    @csrf
    <label for="code">6-digit code</label>
    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus>
    <button class="btn full" type="submit">Confirm and sign in</button>
  </form>
@endsection
