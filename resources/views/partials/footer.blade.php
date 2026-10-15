@php($newTab = request()->is('l/*')) {{-- on pay pages, never navigate away from the payment form --}}
<footer class="footer">
  <span>&copy; {{ date('Y') }} AmpliPay Group Inc.</span>
  <a href="{{ config('amplirail.legal.terms') }}" data-legal-popup @if ($newTab) target="_blank" rel="noopener" @endif>Terms of service</a>
  <a href="{{ config('amplirail.legal.privacy') }}" data-legal-popup @if ($newTab) target="_blank" rel="noopener" @endif>Privacy policy</a>
  <a href="{{ config('amplirail.legal.security') }}" data-legal-popup @if ($newTab) target="_blank" rel="noopener" @endif>Security</a>
  <a href="{{ config('amplirail.legal.status') }}">System status</a>
</footer>
