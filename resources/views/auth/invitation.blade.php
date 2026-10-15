@extends('layouts.auth')
@section('title', 'Set your password')
@section('card')
  <h1>Set your password</h1>
  <p class="muted">At least 12 characters with upper and lower case letters, a number and a symbol.</p>
  <form method="post" action="{{ route($portal.'.invitation.submit', $token) }}">
    @csrf
    <label for="password">New password</label>
    <input id="password" name="password" type="password" autocomplete="new-password" required>
    <label for="password_confirmation">Confirm password</label>
    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
    <button class="btn full" type="submit">Set password</button>
  </form>
@endsection
