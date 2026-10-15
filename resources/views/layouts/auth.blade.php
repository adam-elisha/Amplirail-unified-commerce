@extends('layouts.base')
@section('body')
<div class="auth">
  <section class="auth-hero">
    <div>
      <div class="brand"><span class="brand-mark">A</span><strong>AmpliRail</strong></div>
      <h1>One rail for every processor, channel and partner.</h1>
      <p>Each role signs in to its own portal. One identity service, separate permissions, and every sign-in is logged.</p>
    </div>
    <div class="small footer-links">
      <a href="{{ config('amplirail.legal.terms') }}" data-legal-popup>Terms of service</a> ·
      <a href="{{ config('amplirail.legal.privacy') }}" data-legal-popup>Privacy policy</a> ·
      <a href="{{ config('amplirail.legal.security') }}" data-legal-popup>Security</a> · &copy; {{ date('Y') }} AmpliPay Group Inc.
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
