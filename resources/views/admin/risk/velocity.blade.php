@extends('layouts.admin')
@section('title', 'Velocity and limits')
@section('crumb', 'Risk')
@section('content')
@php
  $scopeLabel = ['portfolio' => 'Whole portfolio', 'merchant' => 'Merchant', 'connector' => 'Processor', 'bin' => 'Card range (BIN)', 'card_brand' => 'Card brand'];
  $dimLabel = ['total' => 'all payments', 'card' => 'each card', 'bin' => 'each BIN'];
  $metricLabel = ['count' => 'attempts', 'amount' => 'amount', 'single_amount' => 'single payment amount'];
@endphp
<section class="card">
  <p class="muted small">Rules run on every payment before it is sent to a processor. A blocked payment is recorded with reason "velocity limit" and is never sent. "Monitor" rules only log a hit for Risk. Processor rules skip that processor for the payment (the next of the merchant's own MIDs is tried); they never move a declined payment. Counters use fixed time windows.</p>
  <div class="table-wrap"><table>
    <thead><tr><th>Rule</th><th>Applies to</th><th>Limit</th><th>Window</th><th>Env</th><th>Action</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse ($rules as $r)
      <tr>
        <td>{{ $r->name }}<div class="mono small muted">{{ $r->id }}</div></td>
        <td class="small">{{ $scopeLabel[$r->scope] ?? $r->scope }}@if ($r->scope_value): {{ $r->scope === 'merchant' ? ($merchants[$r->scope_value] ?? $r->scope_value) : $r->scope_value }}@endif<div class="muted">per {{ $dimLabel[$r->dimension] ?? $r->dimension }}</div></td>
        <td class="small">{{ in_array($r->metric, ['amount', 'single_amount'], true) ? money($r->limit_value, $r->currency) : number_format($r->limit_value).' attempts' }}<div class="muted">{{ $metricLabel[$r->metric] ?? $r->metric }}</div></td>
        <td class="small">{{ $r->metric === 'single_amount' ? '-' : ($r->window_minutes >= 1440 ? ($r->window_minutes / 1440).' day(s)' : ($r->window_minutes >= 60 ? ($r->window_minutes / 60).' hour(s)' : $r->window_minutes.' min')) }}</td>
        <td><span class="badge {{ $r->environment === 'any' ? 'grey' : $r->environment }}">{{ $r->environment }}</span></td>
        <td><span class="badge {{ $r->action === 'block' ? 'red' : 'amber' }}">{{ $r->action }}</span></td>
        <td>@include('partials.state', ['state' => $r->status === 'active' ? 'active' : 'suspended'])</td>
        <td>@if ($canEdit)<form method="post" action="{{ route('admin.risk.velocity.toggle', $r->id) }}">@csrf<button class="btn secondary small" type="submit">{{ $r->status === 'active' ? 'Pause' : 'Resume' }}</button></form>@endif</td>
      </tr>
    @empty
      <tr><td colspan="8" class="muted">No rules yet. Merchant screening limits (max ticket, per-card per hour) still apply.</td></tr>
    @endforelse
    </tbody>
  </table></div>
</section>
@if ($canEdit)
<section class="card">
  <h2>Add a rule</h2>
  <form method="post" action="{{ route('admin.risk.velocity.store') }}" class="grid g3">@csrf
    <div><label for="name">Name</label><input id="name" name="name" type="text" required maxlength="120" value="{{ old('name') }}" placeholder="e.g. 3 attempts per card per 10 minutes"></div>
    <div><label for="scope">Applies to</label><select id="scope" name="scope">@foreach ($scopeLabel as $k => $l)<option value="{{ $k }}" @selected(old('scope') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div><label for="scope_value">Which one</label><input id="scope_value" name="scope_value" type="text" maxlength="60" list="scope-values" value="{{ old('scope_value') }}" placeholder="merchant ID, processor, BIN (6-8 digits) or visa / mastercard / amex / discover">
      <datalist id="scope-values">@foreach ($merchants as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach @foreach ($connectors as $c)<option value="{{ $c }}">processor</option>@endforeach<option value="visa"></option><option value="mastercard"></option><option value="amex"></option><option value="discover"></option></datalist></div>
    <div><label for="dimension">Count per</label><select id="dimension" name="dimension">@foreach ($dimLabel as $k => $l)<option value="{{ $k }}" @selected(old('dimension') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div><label for="metric">Measure</label><select id="metric" name="metric">@foreach ($metricLabel as $k => $l)<option value="{{ $k }}" @selected(old('metric') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div><label for="limit">Limit (count, or amount like 2500.00)</label><input id="limit" name="limit" type="text" required maxlength="20" value="{{ old('limit') }}"></div>
    <div><label for="currency">Currency (amount limits)</label><input id="currency" name="currency" type="text" maxlength="3" value="{{ old('currency', 'USD') }}"></div>
    <div><label for="window_minutes">Window in minutes</label><input id="window_minutes" name="window_minutes" type="number" min="1" max="44640" required value="{{ old('window_minutes', 60) }}"><div class="muted small">60 = hour, 1440 = day, 10080 = week, 43200 = 30 days</div></div>
    <div><label for="environment">Environment</label><select id="environment" name="environment"><option value="live">live</option><option value="sandbox">sandbox</option><option value="any">any</option></select></div>
    <div><label for="action">Action</label><select id="action" name="action"><option value="block">Block the payment</option><option value="monitor">Monitor only (log)</option></select></div>
    <div class="actions" style="align-self:end"><button class="btn" type="submit">Add rule</button></div>
  </form>
</section>
@endif
@endsection
