@extends('layouts.admin')
@section('title', 'Disputes and chargebacks')
@section('crumb', 'Payments')
@section('content')
<div class="grid g3">
  <section class="card stat"><div class="label">Needs merchant response</div><div class="value">{{ $stats['needs_response'] }}</div></section>
  <section class="card stat"><div class="label">Under review with the network</div><div class="value">{{ $stats['under_review'] }}</div></section>
  <section class="card stat"><div class="label">Due in 3 days or less</div><div class="value">{{ $stats['due_soon'] }}</div></section>
</div>
<section class="card">
  <form method="get" class="inline-form">
    <div><label for="status">Status</label><select id="status" name="status"><option value="">All</option>@foreach (['needs_response','under_review','won','lost','accepted'] as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ str_replace('_', ' ', $s) }}</option>@endforeach</select></div>
    <div><label for="reason_category">Reason</label><select id="reason_category" name="reason_category"><option value="">All</option>@foreach ($categories as $k => $label)<option value="{{ $k }}" @selected(request('reason_category') === $k)>{{ $label }}</option>@endforeach</select></div>
    <div><label for="environment">Environment</label><select id="environment" name="environment"><option value="">All</option><option @selected(request('environment') === 'sandbox')>sandbox</option><option @selected(request('environment') === 'live')>live</option></select></div>
    <input type="hidden" name="merchant_id" value="{{ request('merchant_id') }}">
    <div><button class="btn secondary" type="submit">Filter</button></div>
  </form>
</section>
<section class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>Opened</th><th>Dispute</th><th>Merchant</th><th>Reason</th><th>Stage</th><th>Status</th><th class="num">Amount</th><th>Respond by</th><th>Env</th></tr></thead>
    <tbody>
    @forelse ($rows as $d)
      @php $left = $d->daysLeft(); @endphp
      <tr>
        <td class="small">{{ $d->created_at->format('M j, Y') }}</td>
        <td><a class="mono small" href="{{ route('admin.disputes.show', $d->id) }}">{{ $d->id }}</a><div class="muted small">case {{ $d->network_case_id }}</div></td>
        <td>{{ $names[$d->merchant_id] ?? $d->merchant_id }}</td>
        <td class="small">{{ $d->categoryLabel() }}@if ($d->reason_code)<div class="muted small">code {{ $d->reason_code }}</div>@endif</td>
        <td class="small">{{ str_replace('_', ' ', $d->stage) }}</td>
        <td>@include('partials.state', ['state' => $d->status])</td>
        <td class="num">{{ money($d->amount_minor, $d->currency) }}</td>
        <td class="small">@if ($d->due_at){{ $d->due_at->format('M j') }}@if ($left !== null)<div class="{{ $left <= 3 ? 'check-bad' : 'muted' }} small">{{ $left < 0 ? 'overdue' : $left.' days left' }}</div>@endif @else - @endif</td>
        <td><span class="badge {{ $d->environment }}">{{ $d->environment }}</span></td>
      </tr>
    @empty
      <tr><td colspan="9" class="muted">No disputes. Record one below when a processor notifies you of a chargeback.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  {{ $rows->links() }}
</section>
@if ($canManage)
<section class="card" id="record">
  <h2>Record a dispute</h2>
  <p class="muted small">Enter the case exactly as the processor reported it. For a chargeback, the amount is taken from the merchant's balance now and returned if the dispute is won. An inquiry moves no money.</p>
  <form method="post" action="{{ route('admin.disputes.store') }}" class="grid g3">@csrf
    <div><label for="transaction_id">Transaction ID</label><input id="transaction_id" name="transaction_id" type="text" required value="{{ old('transaction_id', $prefill) }}" placeholder="txn_..."></div>
    <div><label for="network_case_id">Processor case number</label><input id="network_case_id" name="network_case_id" type="text" required value="{{ old('network_case_id') }}"></div>
    <div><label for="amount">Amount (blank = full remaining amount)</label><input id="amount" name="amount" type="text" inputmode="decimal" value="{{ old('amount') }}"></div>
    <div><label for="stage">Stage</label><select id="stage" name="stage">@foreach ($stages as $s)<option value="{{ $s }}" @selected(old('stage', 'chargeback') === $s)>{{ str_replace('_', ' ', $s) }}</option>@endforeach</select></div>
    <div><label for="reason_category_new">Reason</label><select id="reason_category_new" name="reason_category">@foreach ($categories as $k => $label)<option value="{{ $k }}" @selected(old('reason_category') === $k)>{{ $label }}</option>@endforeach</select></div>
    <div><label for="reason_code">Network reason code (optional)</label><input id="reason_code" name="reason_code" type="text" value="{{ old('reason_code') }}" placeholder="e.g. 10.4"></div>
    <div><label for="due_at">Respond by (default 10 days)</label><input id="due_at" name="due_at" type="date" value="{{ old('due_at') }}"></div>
    <div class="actions" style="align-self:end"><button class="btn" type="submit">Record dispute</button></div>
  </form>
</section>
@endif
@endsection
