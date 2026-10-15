@extends('layouts.admin')
@section('title', 'Pre-dispute alerts')
@section('crumb', 'Payments · Disputes')
@section('content')
@php $label = ['refunded' => ['green', 'Refunded'], 'already_refunded' => ['grey', 'Already refunded'], 'chargeback_open' => ['amber', 'Chargeback already open'], 'declined' => ['amber', 'Declined (no fast refund)'], 'not_found' => ['red', 'Sale not found'], 'refund_failed' => ['red', 'Refund failed'], 'rdr_resolved' => ['green', 'Resolved by RDR']]; @endphp
<div class="grid g3">
  <section class="card stat"><div class="label">Alerts, last 30 days</div><div class="value">{{ $stats['received'] }}</div></section>
  <section class="card stat"><div class="label">Refunded automatically</div><div class="value">{{ $stats['refunded'] }}</div><div class="muted small">Each one is a chargeback avoided</div></section>
  <section class="card stat"><div class="label">Declined</div><div class="value">{{ $stats['declined'] }}</div><div class="muted small">Likely to become chargebacks</div></section>
</div>
@if ($newSecret)
  <section class="card"><p class="alert info"><strong>Copy now, shown once.</strong> client_id <span class="mono">{{ $newSecret['client_id'] }}</span> · client_secret <span class="mono">{{ $newSecret['secret'] }}</span></p></section>
@endif
<section class="card">
  <form method="get" class="inline-form">
    <div><label for="provider">Network</label><select id="provider" name="provider"><option value="">All</option>@foreach ($providers as $k => $l)<option value="{{ $k }}" @selected(request('provider') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div><label for="status">Outcome</label><select id="status" name="status"><option value="">All</option>@foreach ($label as $k => [$c, $l])<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div><button class="btn secondary" type="submit">Filter</button></div>
  </form>
  <div class="table-wrap"><table>
    <thead><tr><th>Received</th><th>Network</th><th>Alert</th><th>Merchant</th><th>Sale</th><th class="num">Amount</th><th>Outcome</th></tr></thead>
    <tbody>
    @forelse ($rows as $a)
      <tr>
        <td class="small">{{ $a->created_at->format('M j H:i') }}<div class="muted">{{ $a->received_via }}</div></td>
        <td class="small">{{ $providers[$a->provider] ?? $a->provider }}</td>
        <td class="mono small">{{ $a->provider_alert_id }}</td>
        <td>{{ $a->merchant_id ? ($merchants[$a->merchant_id] ?? $a->merchant_id) : '-' }}</td>
        <td>@if ($a->transaction_id)<a class="mono small" href="{{ route('admin.transactions.show', $a->transaction_id) }}">{{ $a->transaction_id }}</a>@else - @endif</td>
        <td class="num">{{ money($a->amount_minor, $a->currency) }}</td>
        <td><span class="badge {{ $label[$a->status][0] ?? '' }}">{{ $label[$a->status][1] ?? $a->status }}</span>@if ($a->outcome_note)<div class="muted small">{{ $a->outcome_note }}</div>@endif</td>
      </tr>
    @empty
      <tr><td colspan="7" class="muted">No alerts yet.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  {{ $rows->links() }}
</section>
<div class="grid g2">
  @if ($canManage)
  <section class="card">
    <h2>Fast refund setting per merchant</h2>
    <p class="muted small">When on, an alert for a sale up to the amount is refunded at once and the cardholder's bank cancels the dispute. Above the amount, or when off, the alert is declined and may become a chargeback.</p>
    <form method="post" action="{{ route('admin.alerts.settings') }}">@csrf
      <label for="merchant_id">Merchant</label><select id="merchant_id" name="merchant_id">@foreach ($merchants as $id => $n)<option value="{{ $id }}">{{ $n }}@if (isset($settings[$id])) · {{ $settings[$id]->auto_refund ? 'on up to '.money($settings[$id]->max_refund_minor, 'USD') : 'off' }}@endif</option>@endforeach</select>
      <label><input type="checkbox" name="auto_refund" value="1"> Refund alerts automatically</label>
      <label for="max_refund">Up to this amount</label><input id="max_refund" name="max_refund" type="text" inputmode="decimal" placeholder="250.00">
      <button class="btn" type="submit">Save</button>
    </form>
  </section>
  <section class="card">
    <h2>Record an alert by hand</h2>
    <form method="post" action="{{ route('admin.alerts.manual') }}">@csrf
      <label for="m_provider">Network</label><select id="m_provider" name="provider">@foreach ($providers as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
      <label for="m_alert">Alert ID</label><input id="m_alert" name="alert_id" type="text" required maxlength="100">
      <label for="m_txn">Transaction ID</label><input id="m_txn" name="transaction_id" type="text" required maxlength="40" placeholder="txn_...">
      <div class="grid g2"><div><label for="m_amount">Amount</label><input id="m_amount" name="amount" type="text" required></div><div><label for="m_cur">Currency</label><input id="m_cur" name="currency" type="text" required maxlength="3" value="USD"></div></div>
      <button class="btn" type="submit">Record and apply the merchant's setting</button>
    </form>
  </section>
  @endif
  @if ($canKeys)
  <section class="card">
    <h2>API access for alert partners</h2>
    <p class="muted small">For Ethoca, Verifi or their resellers. They call <span class="mono">POST /v1/oauth/token</span> with these credentials, then <span class="mono">POST /v1/alerts</span>, and get the outcome in the same call. Credentials can only send alerts.</p>
    <ul class="small">@forelse ($clients as $c)<li>{{ $c->name }} · <span class="mono">{{ $c->client_id }}</span>{{ $c->revoked_at ? ' (revoked)' : '' }}</li>@empty<li class="muted">None yet.</li>@endforelse</ul>
    <form method="post" action="{{ route('admin.alerts.client') }}" class="inline-form">@csrf
      <div><label for="client_name">Partner name</label><input id="client_name" name="name" type="text" required maxlength="120"></div>
      <div><button class="btn" type="submit">Create credentials</button></div>
    </form>
  </section>
  @endif
</div>
@endsection
