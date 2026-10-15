@extends('layouts.admin')
@section('title', 'Transaction')
@section('crumb', 'Payments · Transactions')
@section('content')
@php $can = fn (string $p) => in_array($p, $permissions ?? [], true); @endphp
<div class="grid g3">
  <div class="span2 grid">
    <section class="card">
      <div class="card-head">
        <div><h2 class="mono">{{ $t->id }}</h2><div class="muted small">{{ $merchant?->displayName() }} · order {{ $t->order_reference }} · correlation <span class="mono">{{ $t->correlation_id }}</span></div></div>
        <div>@include('partials.state', ['state' => $t->status]) @include('partials.state', ['state' => $t->state]) <span class="badge {{ $t->environment }}">{{ $t->environment }}</span></div>
      </div>
      <div class="grid g4">
        <div class="stat"><div class="label">Amount</div><div class="value">{{ money($t->amount_minor, $t->currency) }}</div></div>
        <div class="stat"><div class="label">Captured</div><div class="value">{{ money($t->captured_minor, $t->currency) }}</div></div>
        <div class="stat"><div class="label">Refunded</div><div class="value">{{ money($t->refunded_minor, $t->currency) }}</div></div>
        <div class="stat"><div class="label">Response</div><div class="value">{{ $t->response_code ?? '-' }}</div><div class="muted small">{{ $t->decline_category ?? $t->reason }}</div></div>
      </div>
      <dl class="kv"><dt>Connector / MID</dt><dd>{{ $t->connector ?? '-' }} · <span class="mono">{{ $t->merchant_account_id ?? '-' }}</span></dd>
        <dt>Provider reference</dt><dd class="mono">{{ $t->provider_txn_id ?? '-' }}</dd><dt>Auth code</dt><dd>{{ $t->auth_code ?? '-' }}</dd>
        <dt>Card</dt><dd>{{ $t->card_last4 ? ucfirst((string) $t->card_brand).' ending '.$t->card_last4 : 'Token only' }}</dd>
        <dt>Channel</dt><dd>{{ $t->channel }} · initiated by {{ $t->initiator }}</dd></dl>
    </section>

    <section class="card">
      <h2>Attempts</h2>
      <div class="table-wrap"><table><thead><tr><th>When</th><th>Operation</th><th>Connector</th><th>MID</th><th>Outcome</th><th>Code</th><th class="num">Latency</th></tr></thead><tbody>
        @foreach ($attempts as $a)
          <tr><td class="small">{{ $a->created_at->format('H:i:s') }}</td><td>{{ $a->operation }}</td><td>{{ $a->connector }}</td><td class="mono small">{{ $a->merchant_account_id }}</td>
            <td>{{ $a->outcome }}{{ $a->retryable ? ' (retryable, failed over)' : '' }}</td><td>{{ $a->response_code }}</td><td class="num">{{ $a->latency_ms }} ms</td></tr>
        @endforeach
      </tbody></table></div>
    </section>

    <section class="card">
      <h2>Ledger</h2><div class="muted small">Append-only double entry. Each journal sums to zero.</div>
      <div class="table-wrap"><table><thead><tr><th>Journal</th><th>Account</th><th class="num">Amount</th></tr></thead><tbody>
        @forelse ($ledger as $p)<tr><td class="mono small">{{ $p['journal_id'] }}</td><td class="mono small">{{ $p['account'] }}</td><td class="num">{{ money($p['amount_minor'], $p['currency']) }}</td></tr>
        @empty<tr><td colspan="3" class="muted">No postings (not captured).</td></tr>@endforelse
      </tbody></table></div>
    </section>

    <section class="card">
      <h2>Webhook deliveries</h2>
      <div class="table-wrap"><table><thead><tr><th>Event</th><th>Status</th><th>Attempts</th><th>Last code</th><th></th></tr></thead><tbody>
        @forelse ($deliveries as $d)
          <tr><td>{{ $d->event_type }} <span class="mono small">{{ $d->id }}</span>{{ $d->replay_of !== '' ? ' (replay)' : '' }}</td><td>@include('partials.state', ['state' => $d->status])</td><td>{{ $d->attempts }}</td><td>{{ $d->last_status_code ?? $d->last_error }}</td>
            <td>@if ($can('webhooks.manage'))<form method="post" action="{{ route('admin.webhooks.replay', $d->id) }}">@csrf<button class="btn secondary small" type="submit">Replay</button></form>@endif</td></tr>
        @empty<tr><td colspan="5" class="muted">No webhook deliveries for this transaction.</td></tr>@endforelse
      </tbody></table></div>
    </section>
  </div>

  <div class="grid">
    @if ($can('payments.refund') && in_array($t->state, ['captured', 'partially_refunded'], true))
      <section class="card">
        <h2>Refund</h2>
        <form method="post" action="{{ route('admin.transactions.refund', $t->id) }}">@csrf
          <label>Amount ({{ $t->currency }})</label><input name="amount" type="text" required value="{{ number_format(($t->captured_minor - $t->refunded_minor) / 100, 2, '.', '') }}">
          <label>Reason</label><input name="reason" type="text">
          <label>Authenticator code (above {{ money((int) config('amplirail.payments.refund_step_up_threshold_minor'), $t->currency) }})</label><input name="step_up_code" inputmode="numeric" maxlength="6">
          <button class="btn full" type="submit">Refund</button></form>
      </section>
    @endif
    @if ($can('payments.void') && in_array($t->state, ['authorized', 'captured'], true) && $t->refunded_minor === 0)
      <section class="card"><h2>Void</h2><form method="post" action="{{ route('admin.transactions.void', $t->id) }}" data-confirm="Void this transaction?">@csrf<button class="btn danger full" type="submit">Void</button></form></section>
    @endif
    @if ($refunds->isNotEmpty())
      <section class="card"><h2>Refunds</h2><div class="rows">@foreach ($refunds as $r)<div><span class="mono small">{{ $r->id }}</span><span>{{ money($r->amount_minor, $r->currency) }} @include('partials.state', ['state' => $r->status])</span></div>@endforeach</div></section>
    @endif
    <section class="card">
      <h2>Event timeline</h2>
      <div class="timeline">
        @foreach ($events as $e)
          <div><span class="mono small">{{ \Illuminate\Support\Carbon::parse($e['occurred_at'])->format('M j H:i:s') }}</span><span>{{ $e['type'] }}<div class="muted small mono">{{ $e['id'] }}</div></span></div>
        @endforeach
      </div>
    </section>
  </div>
</div>
@endsection
