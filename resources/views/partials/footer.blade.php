@php($newTab = request()->is('l/*')) {{-- on pay pages, never navigate away from the payment form --}}
<footer class="footer">
  <span>&copy; {{ date('Y') }} {{ $brand->company ?? 'AmpliPay Group Inc.' }}</span>
  <a href="{{ $brand->termsUrl ?? config('amplirail.legal.terms') }}" data-legal-popup @if ($newTab) target="_blank" rel="noopener" @endif>Terms of service</a>
  <a href="{{ $brand->privacyUrl ?? config('amplirail.legal.privacy') }}" data-legal-popup @if ($newTab) target="_blank" rel="noopener" @endif>Privacy policy</a>
  <a href="{{ $brand->securityUrl ?? config('amplirail.legal.security') }}" data-legal-popup @if ($newTab) target="_blank" rel="noopener" @endif>Security</a>
  <a href="{{ config('amplirail.legal.status') }}">System status</a>
</footer>
