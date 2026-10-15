@extends('layouts.base')
@section('title', 'Pay '.$merchant)
@push('head')
  @if ($collectJs !== '')
    {{-- Processor-hosted card fields. Verify the script URL and attributes against docs/providers/nmi/ before go-live. --}}
    <script src="{{ $collectJs }}" data-tokenization-key="{{ $tokenizationKey }}" nonce="{{ $cspNonce }}"></script>
    <script src="{{ asset('js/pay.js') }}?v={{ config('amplirail.version') }}" nonce="{{ $cspNonce }}" defer></script>
  @endif
@endpush
@section('body')
<main class="pay">
  <div class="card">
    <div class="card-head">
      <div><div class="muted small">Payment request from</div><h1>{{ $merchant }}</h1></div>
      <span class="badge {{ $link->environment === 'live' ? 'live' : 'sandbox' }}">{{ ucfirst($link->environment) }}</span>
    </div>
    <div class="amount">{{ $amount }}</div>
    <p class="muted">Reference {{ $link->reference }}@if ($link->description) · {{ $link->description }}@endif</p>

    @if ($link->status !== 'open' || $link->expires_at->isPast())
      <div class="alert info">This payment request is {{ $link->status === 'paid' ? 'already paid' : 'no longer available' }}.</div>
    @else
      <form method="post" action="{{ route('pay.submit', $token) }}" id="pay-form">
        @csrf
        @if ($link->environment === 'sandbox')
          <div class="alert info small">Sandbox: no real cards. Pick a simulator token and scenario.</div>
          <label for="payment_token">Simulator token</label>
          <select id="payment_token" name="payment_token">
            <option value="tok_sim_approved">tok_sim_approved (approves)</option>
            <option value="tok_sim_unknown">tok_sim_unknown (invalid token decline)</option>
          </select>
          <label for="scenario">Scenario</label>
          <select id="scenario" name="scenario">
            <option value="">None</option><option value="sim_avs_fail">AVS mismatch (approved)</option>
            <option value="sim_timeout">Provider timeout</option><option value="sim_outage">Provider outage</option>
          </select>
          <input type="hidden" name="card_brand" value="visa"><input type="hidden" name="card_last4" value="1111">
        @elseif ($collectJs !== '')
          <p class="small muted">Card details are entered in fields hosted by our payment processor and never reach AmpliRail or {{ $merchant }}.</p>
          <div id="ccnumber"></div><div id="ccexp"></div><div id="cvv"></div>
          <input type="hidden" name="payment_token" id="payment_token">
          <input type="hidden" name="card_brand" id="card_brand"><input type="hidden" name="card_last4" id="card_last4">
        @else
          <div class="alert err">Card payments are not available yet for this merchant.</div>
        @endif
        <button class="btn full" type="submit" id="pay-button">Pay {{ $amount }}</button>
      </form>
    @endif
  </div>
  @include('partials.footer')
</main>
@endsection
