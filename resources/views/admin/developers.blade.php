@extends('layouts.admin')
@section('title', 'Developers and system')
@section('crumb', 'Platform')
@section('content')
<div class="grid g3">
  <section class="card">
    <h2>Connector health</h2>
    <div class="rows">@foreach ($health['connectors'] as $c)<div><span>{{ $c['name'] }} <span class="mono small">{{ $c['connector'] }}</span></span><span>@include('partials.state', ['state' => $c['status'] === 'healthy' ? 'enabled' : ($c['status'] === 'degraded' ? 'failed' : 'disabled')]) {{ $c['attempts_1h'] }} attempts/h</span></div>@endforeach</div>
  </section>
  <section class="card">
    <h2>Event outbox</h2>
    <dl class="kv"><dt>Unpublished events</dt><dd>{{ $backlog }}</dd><dt>Oldest unpublished</dt><dd>{{ $oldestUnpublished?->diffForHumans() ?? '-' }}</dd>
      <dt>Webhooks 24 h</dt><dd>{{ $health['webhooks']['delivered'] }} delivered · {{ $health['webhooks']['failed'] }} failed · {{ $health['webhooks']['pending'] }} pending</dd></dl>
  </section>
  <section class="card">
    <h2>Feature flags</h2>
    <p class="muted small">All flags fail closed. Changes are audited. go_live_gate off means no merchant can be activated.</p>
    @foreach ($flags as $flag => $on)
      <form method="post" action="{{ route('admin.developers.flag', $flag) }}" class="inline-form">@csrf
        <div><strong>{{ $flag }}</strong> <span class="badge {{ $on ? 'green' : 'grey' }}">{{ $on ? 'On' : 'Off' }}</span></div>
        @if ($canFlag)
          <input type="hidden" name="enabled" value="{{ $on ? 0 : 1 }}">
          <div><input name="reason" type="text" required minlength="5" placeholder="Reason"></div>
          <div><button class="btn secondary small" type="submit">Turn {{ $on ? 'off' : 'on' }}</button></div>
        @endif
      </form>
    @endforeach
  </section>
</div>
<section class="card">
  <h2>Simulator scenarios</h2>
  <p class="muted small">Sandbox only. Use token <code>tok_sim_approved</code>. Master document 7.1.</p>
  <div class="table-wrap"><table><thead><tr><th>Input</th><th>Result</th></tr></thead><tbody>
    <tr><td>Any amount not listed</td><td>approved</td></tr>
    <tr><td>Amount ending .02 (10.02)</td><td>declined, do_not_honor (05), never rerouted</td></tr>
    <tr><td>Amount ending .51</td><td>declined, insufficient_funds (soft)</td></tr>
    <tr><td>Amount ending .59</td><td>declined, fraud_suspected</td></tr>
    <tr><td>Amount ending .65</td><td>authentication_required (soft)</td></tr>
    <tr><td>Amount ending .99 or <code>simulator: sim_timeout</code></td><td>timeout, retryable: fails over to the merchant's next MID</td></tr>
    <tr><td><code>simulator: sim_outage</code></td><td>provider_unavailable, retryable</td></tr>
    <tr><td><code>simulator: sim_avs_fail</code></td><td>approved with AVS mismatch</td></tr>
    <tr><td>Repeated Idempotency-Key</td><td>original result returned (Idempotent-Replayed: true)</td></tr>
  </tbody></table></div>
</section>
<section class="card">
  <h2>Recent webhook deliveries</h2>
  <div class="table-wrap"><table><thead><tr><th>When</th><th>Event</th><th>Merchant</th><th>Status</th><th>Attempts</th><th>Next attempt</th><th>Last result</th></tr></thead><tbody>
    @forelse ($deliveries as $d)
      <tr><td class="small">{{ $d->created_at->format('M j H:i:s') }}</td><td>{{ $d->event_type }}</td><td class="mono small">{{ $d->merchant_id }}</td><td>@include('partials.state', ['state' => $d->status])</td><td>{{ $d->attempts }}</td><td class="small">{{ $d->next_attempt_at?->diffForHumans() ?? '-' }}</td><td class="small">{{ $d->last_status_code ?? $d->last_error }}</td></tr>
    @empty<tr><td colspan="7" class="muted">No deliveries.</td></tr>@endforelse
  </tbody></table></div>
</section>
@endsection
