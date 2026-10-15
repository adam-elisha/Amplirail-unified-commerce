@extends('layouts.merchant')
@section('title', 'Transaction detail')
@section('content')
@php $can = fn (string $p) => in_array($p, $permissions ?? [], true); @endphp
<div class="grid g3">
  <section class="card span2">
    <div class="card-head"><div><h2>{{ $t->order_reference }}</h2><div class="mono small muted">{{ $t->id }}</div></div><div>@include('partials.state', ['state' => $t->status]) @include('partials.state', ['state' => $t->state])</div></div>
    <dl class="kv">
      <dt>Amount</dt><dd>{{ money($t->amount_minor, $t->currency) }}</dd><dt>Captured</dt><dd>{{ money($t->captured_minor, $t->currency) }}</dd>
      <dt>Refunded</dt><dd>{{ money($t->refunded_minor, $t->currency) }}</dd><dt>Card</dt><dd>{{ $t->card_last4 ? ucfirst((string) $t->card_brand).' ending '.$t->card_last4 : 'Token only' }}</dd>
      <dt>Result</dt><dd>{{ $t->response_code }} {{ $t->decline_category ? '· '.str_replace('_', ' ', $t->decline_category) : '' }}</dd><dt>Channel</dt><dd>{{ str_replace('_', ' ', $t->channel) }}</dd>
      <dt>Created</dt><dd>{{ $t->created_at->format('M j, Y H:i:s') }} UTC</dd>
    </dl>
    @if ($refunds->isNotEmpty())<h3>Refunds</h3><div class="rows">@foreach ($refunds as $r)<div><span>{{ $r->created_at->format('M j H:i') }} {{ $r->reason }}</span><span>{{ money($r->amount_minor, $r->currency) }} @include('partials.state', ['state' => $r->status])</span></div>@endforeach</div>@endif
  </section>
  <div class="grid">
    @if ($can('payments.refund') && in_array($t->state, ['captured', 'partially_refunded'], true))
      <section class="card"><h2>Refund</h2>
        <form method="post" action="{{ route('merchant.transactions.refund', $t->id) }}">@csrf
          <input type="hidden" name="form_key" value="{{ \App\Support\Ids::new('rf') }}">
          <label>Amount</label><input name="amount" type="text" required value="{{ number_format(($t->captured_minor - $t->refunded_minor) / 100, 2, '.', '') }}">
          <label>Reason</label><input name="reason" type="text">
          <label>Email code (refunds above {{ money($threshold, $t->currency) }})</label><input name="code" inputmode="numeric" maxlength="8">
          <button class="btn full" type="submit">Refund</button>
        </form>
        <form method="post" action="{{ route('merchant.step-up') }}">@csrf<button class="btn secondary small full" type="submit">Email me a code</button></form>
      </section>
    @endif
    @if ($can('payments.void') && in_array($t->state, ['authorized', 'captured'], true) && $t->refunded_minor === 0)
      <section class="card"><h2>Void</h2><form method="post" action="{{ route('merchant.transactions.void', $t->id) }}" data-confirm="Void this payment?">@csrf<input type="hidden" name="form_key" value="{{ \App\Support\Ids::new('vd') }}"><button class="btn danger full" type="submit">Void</button></form></section>
    @endif
  </div>
</div>
@endsection
