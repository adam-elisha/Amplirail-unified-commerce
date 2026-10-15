@extends('layouts.base')
@section('body')
<div class="auth">
  <section class="auth-hero">
    <div>
      <div class="brand">@include('partials.brand-mark')<strong>{{ $brand->name ?? 'AmpliRail' }}</strong></div>
      <h1>{{ $brand->tagline ?? 'One rail for every processor, channel and partner.' }}</h1>
      <p>Each role signs in to its own portal. One identity service, separate permissions, and every sign-in is logged.</p>
    </div>
    <div class="small footer-links">
      <a href="{{ $brand->termsUrl ?? config('amplirail.legal.terms') }}" data-legal-popup>Terms of service</a> ·
      <a href="{{ $brand->privacyUrl ?? config('amplirail.legal.privacy') }}" data-legal-popup>Privacy policy</a> ·
      <a href="{{ $brand->securityUrl ?? config('amplirail.legal.security') }}" data-legal-popup>Security</a> · &copy; {{ date('Y') }} {{ $brand->company ?? 'AmpliPay Group Inc.' }}
    </div>
  </section>
  <section class="auth-panel">
    <div class="auth-card">
      @include('partials.flash')
      @yield('card')
    </div>
  </section>
</div>
@endsection
