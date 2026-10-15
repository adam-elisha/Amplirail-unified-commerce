@extends('layouts.merchant')
@section('title', 'Dispute')
@section('content')
@php $can = fn (string $p) => in_array($p, $permissions ?? [], true); $left = $d->daysLeft(); @endphp
<div class="grid g3">
  <div class="span2 grid">
    <section class="card">
      <div class="card-head">
        <div><h2>{{ $d->categoryLabel() }}</h2><div class="muted small mono">{{ $d->id }}</div></div>
        <div>@include('partials.state', ['state' => $d->status])</div>
      </div>
      <div class="grid g3">
        <div class="stat"><div class="label">Disputed amount</div><div class="value">{{ money($d->amount_minor, $d->currency) }}</div></div>
        <div class="stat"><div class="label">Respond by</div><div class="value">{{ $d->due_at?->format('M j, Y') ?? '-' }}</div><div class="{{ ($left ?? 99) <= 3 ? 'check-bad' : 'muted' }} small">{{ $left === null ? '' : ($left < 0 ? 'overdue' : $left.' days left') }}</div></div>
        <div class="stat"><div class="label">Stage</div><div class="value">{{ ucfirst(str_replace('_', ' ', $d->stage)) }}</div></div>
      </div>
      <dl class="kv">
        <dt>Payment</dt><dd>@if ($txn)<a href="{{ route('merchant.transactions.show', $txn->id) }}">{{ $txn->order_reference }}</a> · {{ money($txn->captured_minor, $txn->currency) }} on {{ $txn->created_at->format('M j, Y') }}@else - @endif</dd>
        <dt>Card</dt><dd>{{ $txn && $txn->card_last4 ? ucfirst((string) $txn->card_brand).' ending '.$txn->card_last4 : '-' }}</dd>
        @if ($d->resolved_at)<dt>Closed</dt><dd>{{ $d->resolved_at->format('M j, Y') }}{{ $d->resolution_note ? ' · '.$d->resolution_note : '' }}</dd>@endif
      </dl>
    </section>
    @if ($d->merchant_response)
      <section class="card">
        <h2>Your response</h2>
        <p style="white-space:pre-wrap">{{ $d->merchant_response }}</p>
        @if (! empty($d->evidence))
          <ul>@foreach ($d->evidence as $e)<li><strong>{{ ucfirst(str_replace('_', ' ', $e['type'])) }}:</strong> {{ $e['description'] }}</li>@endforeach</ul>
        @endif
      </section>
    @endif
    <section class="card">
      <h2>History</h2>
      <div class="timeline">
        @foreach ($events as $e)
          <div><span class="small">{{ \Illuminate\Support\Carbon::parse($e['occurred_at'])->format('M j H:i') }}</span><span>{{ str_replace(['dispute.', '_'], ['', ' '], $e['type']) }}</span></div>
        @endforeach
      </div>
    </section>
  </div>
  <div class="grid">
    @if ($d->status === 'needs_response' && $can('payments.refund'))
      <section class="card">
        <h2>Respond</h2>
        <form method="post" action="{{ route('merchant.disputes.respond', $d->id) }}">@csrf
          @include('merchant.disputes.partials.evidence')
          <button class="btn full" type="submit">Submit response</button>
        </form>
      </section>
      <section class="card">
        <h2>Accept the chargeback</h2>
        <p class="muted small">The amount stays with the cardholder and the case closes. This cannot be undone.</p>
        <form method="post" action="{{ route('merchant.disputes.accept', $d->id) }}">@csrf<button class="btn danger full" type="submit">Accept chargeback</button></form>
      </section>
    @elseif ($d->status === 'under_review')
      <section class="card"><h2>Under review</h2><p class="muted small">Your response was sent. The card network usually decides within 30 to 75 days. We will update this page when a decision arrives.</p></section>
    @endif
  </div>
</div>
@endsection
