@extends('layouts.admin')
@section('title', 'Overview')
@section('content')
<div class="grid g4">
  <div class="card stat"><div class="label">Approval rate, 30 days</div><div class="value">{{ $approval['approval_rate'] !== null ? $approval['approval_rate'].'%' : '-' }}</div><div class="muted small">{{ $approval['approved'] }} approved · {{ $approval['declined'] }} declined</div></div>
  <div class="card stat"><div class="label">Active merchants</div><div class="value">{{ $activeMerchants }}</div><div class="muted small">{{ $testMerchants }} in test mode or validation</div></div>
  <div class="card stat"><div class="label">Pending approvals</div><div class="value">{{ $pendingApprovals }}</div><div class="muted small"><a href="{{ route('admin.approvals') }}">Open queue</a></div></div>
  <div class="card stat"><div class="label">Time to live, 90 days (median)</div><div class="value">{{ $cc['time_in_state']['time_to_live']['median_hours'] !== null ? round($cc['time_in_state']['time_to_live']['median_hours'] / 24, 1).' d' : '-' }}</div><div class="muted small">{{ $cc['time_in_state']['time_to_live']['samples'] }} merchants · target 4 business days</div></div>
</div>

<section class="card" aria-labelledby="pipeline-h">
  <div class="card-head">
    <div><h2 id="pipeline-h">Onboarding pipeline</h2><div class="muted small">Server-controlled states from CRM handoff to live. Change is versus 24 hours ago.</div></div>
    <div class="actions">
      @if ($cc['sample_data'])<a class="btn secondary small" href="{{ route('admin.overview') }}">Show live data</a>@else<a class="btn secondary small" href="{{ route('admin.overview', ['sample' => 1]) }}">Sample data</a>@endif
      <a class="btn secondary small" href="{{ route('admin.merchants.index') }}">View merchants</a>
    </div>
  </div>
  <div class="pipeline">
    @foreach ($cc['pipeline'] as $p)
      <div class="pipe"><div class="muted small">{{ $p['bucket'] }}</div><div class="n">{{ $p['count'] }}</div>
        <div class="small {{ $p['change'] > 0 ? 'delta-up' : ($p['change'] < 0 ? 'delta-down' : 'muted') }}">{{ $p['change'] > 0 ? '+' : '' }}{{ $p['change'] }} today</div></div>
    @endforeach
  </div>
</section>

<section class="card" aria-labelledby="sla-h">
  <h2 id="sla-h">Activation command center</h2>
  <div class="muted small">Blocker, owner, next action and deadline for every in-flight merchant. Rows past {{ (int) (config('amplirail.onboarding.at_risk_fraction') * 100) }}% of the state SLA are highlighted.</div>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Merchant</th><th>State</th><th class="num">Days in state</th><th>Blocker</th><th>Owner</th><th>Next action</th><th>Deadline</th></tr></thead>
    <tbody>
    @forelse ($cc['merchants'] as $r)
      <tr class="{{ $r['overdue'] ? 'overdue' : ($r['at_risk'] ? 'risk' : '') }}">
        <td>@if (! $cc['sample_data'])<a href="{{ route('admin.merchants.show', $r['merchant_id']) }}">{{ $r['name'] }}</a>@else{{ $r['name'] }}@endif<div class="muted small mono">{{ $r['public_id'] }}</div></td>
        <td>@include('partials.state', ['state' => $r['state']])</td>
        <td class="num">{{ $r['days_in_state'] }}</td>
        <td>{{ $r['blocker'] }}</td><td>{{ $r['owner'] }}</td><td>{{ $r['next_action'] }}</td>
        <td class="small">{{ \Illuminate\Support\Carbon::parse($r['deadline'])->format('M j H:i') }}@if ($r['overdue']) <span class="badge red">overdue</span>@elseif ($r['at_risk']) <span class="badge amber">at risk</span>@endif</td>
      </tr>
    @empty
      <tr><td colspan="7" class="muted">No merchants in flight.</td></tr>
    @endforelse
    </tbody>
  </table>
  </div>
</section>

<div class="grid g3">
  <section class="card">
    <h2>Time in state, 90 days</h2>
    <div class="rows">
      @forelse ($cc['time_in_state']['per_state'] as $s)
        <div><span>{{ str_replace('_', ' ', $s['state']) }}</span><span class="muted">median {{ $s['median_hours'] }} h · avg {{ $s['average_hours'] }} h · n={{ $s['samples'] }}</span></div>
      @empty
        <div class="muted">No completed states yet.</div>
      @endforelse
    </div>
  </section>
  <section class="card">
    <h2>Connector health</h2><div class="muted small">Last hour of attempts</div>
    <div class="rows">
      @foreach ($cc['health']['connectors'] as $c)
        <div><span>{{ $c['name'] }}</span>
          <span>@if ($c['status'] === 'healthy')<span class="badge green">Healthy{{ $c['avg_latency_ms'] ? ' · '.$c['avg_latency_ms'].' ms' : '' }}</span>@elseif ($c['status'] === 'degraded')<span class="badge amber">Degraded · {{ $c['error_rate'] }}% errors</span>@else<span class="badge grey">Not connected</span>@endif</span></div>
      @endforeach
      <div><span>Webhook engine</span><span class="badge {{ ($cc['health']['webhooks']['success_rate'] ?? 100) >= 99 ? 'green' : 'amber' }}">{{ $cc['health']['webhooks']['success_rate'] !== null ? $cc['health']['webhooks']['success_rate'].'% delivered' : 'No deliveries yet' }}</span></div>
    </div>
  </section>
  <section class="card">
    <h2>Volume by connector, 30 days</h2>
    <div class="rows">
      @forelse ($volume as $v)
        <div><span>{{ $v['connector'] }} <span class="badge {{ $v['environment'] }}">{{ $v['environment'] }}</span></span><span>{{ money($v['amount_minor'], $v['currency']) }}</span></div>
      @empty
        <div class="muted">No approved volume yet.</div>
      @endforelse
    </div>
  </section>
</div>
@endsection
