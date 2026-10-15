@extends('layouts.auth')
@section('title', 'AmpliRail')
@section('card')
  <h1>AmpliRail</h1>
  <p class="muted">Choose your portal.</p>
  <a class="btn full" href="{{ route('admin.login') }}">Admin console</a>
  <a class="btn secondary full" href="{{ route('merchant.login') }}">Merchant portal</a>
  <p class="small muted">Developers: the API lives at /v1 (see openapi/amplirail-v1.yaml).</p>
@endsection
