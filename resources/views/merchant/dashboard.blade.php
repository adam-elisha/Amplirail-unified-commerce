@extends('layouts.merchant')
@section('title', 'Dashboard')
@section('content')
@php $order = array_keys($progress); $pos = array_search($m->state, $order, true); @endphp
<section class="card">
  <h2>Go-live checklist</h2>
  <div class="muted small">{{ $m->state === 'active' ? 'Your account is live.' : 'Your account is in '.str_replace('_', ' ', $m->state).'. Sandbox is ready to use now.' }}</div>
  <div class="progress">
    @foreach ($progress as $state => $label)
      @php $i = array_search($state, $order, true); @endphp
      <div class="{{ ($pos !== false && $i < $pos) || $m->state === 'active' ? 'done' : ($state === $m->state ? 'current' : '') }}">{{ $label }}</div>
    @endforeach
  </div>
  @if (! $checklist['complete'])
    <div class="alert info small full">We still need: {{ implode(', ', $checklist['outstanding']) }}. <a href="{{ route('merchant.documents.index') }}">Upload documents</a></div>
  @endif
</section>
<div class="grid g3">
  <div class="card stat"><div class="label">Sales, 30 days ({{ $env }})</div><div class="value">{{ money($summary['amount_minor'], $summary['currency'] ?? $m->currency) }}</div><div class="muted small">{{ $summary['count'] }} approved payments</div></div>
  <div class="card stat"><div class="label">Merchant ID</div><div class="value mono">{{ $m->public_id }}</div><div class="muted small">{{ $m->displayName() }}</div></div>
  <div class="card"><h2>Quick actions</h2><div class="actions">
    @if (in_array('payments.refund', $permissions ?? [], true))
      <a class="btn" href="{{ route('merchant.terminal') }}">Take a payment</a><a class="btn secondary" href="{{ route('merchant.links.index') }}">Payment request</a>
    @endif
    @if (in_array('api_keys.manage', $permissions ?? [], true))<a class="btn secondary" href="{{ route('merchant.developers') }}">API keys</a>@endif
  </div></div>
</div>
<section class="card">
  <h2>Recent payments</h2>
  <div class="table-wrap"><table><thead><tr><th>When</th><th>Reference</th><th>Status</th><th class="num">Amount</th></tr></thead><tbody>
    @forelse ($recent as $t)
      <tr><td class="small">{{ $t->created_at->format('M j H:i') }}</td><td><a href="{{ route('merchant.transactions.show', $t->id) }}">{{ $t->order_reference }}</a></td><td>@include('partials.state', ['state' => $t->status])</td><td class="num">{{ money($t->amount_minor, $t->currency) }}</td></tr>
    @empty<tr><td colspan="4" class="muted">No payments yet in {{ $env }}.</td></tr>@endforelse
  </tbody></table></div>
</section>
@endsection
