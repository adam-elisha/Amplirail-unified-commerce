@extends('layouts.merchant')
@section('title', 'Virtual terminal')
@section('content')
<section class="card">
  <h2>Card payment</h2>
  @if ($env === 'sandbox')
    <p class="muted small">Sandbox: use simulator tokens. Amounts ending .02, .51, .59 or .65 decline; .99 times out and fails over.</p>
    <form method="post" action="{{ route('merchant.terminal.charge') }}" class="inline-form">@csrf
      <input type="hidden" name="form_key" value="{{ $formKey }}">
      <div><label>Amount ({{ $m->currency }})</label><input name="amount" type="text" required placeholder="25.00" value="{{ old('amount') }}"></div>
      <div><label>Order reference</label><input name="reference" type="text" required value="{{ old('reference') }}"></div>
      <div><label>Simulator token</label><select name="payment_token"><option value="tok_sim_approved">tok_sim_approved</option><option value="tok_sim_unknown">tok_sim_unknown</option></select></div>
      <div><label><input type="checkbox" name="capture" value="1" checked> Capture now</label></div>
      <div><button class="btn" type="submit">Charge</button></div>
    </form>
  @else
    <div class="alert info">Live card entry uses the processor's hosted card fields so card numbers never touch AmpliRail. Send a payment request link, or use the API with hosted fields, until the live virtual terminal fields are enabled for your account.</div>
    <a class="btn" href="{{ route('merchant.links.index') }}">Create a payment request</a>
  @endif
</section>
@endsection
