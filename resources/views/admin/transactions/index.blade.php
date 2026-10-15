@extends('layouts.admin')
@section('title', 'Transactions')
@section('crumb', 'Payments')
@section('content')
<section class="card">
  <form method="get" class="inline-form">
    <div><label for="q">Transaction ID, order or provider reference</label><input id="q" name="q" type="text" value="{{ request('q') }}"></div>
    <div><label for="status">Status</label><select id="status" name="status"><option value="">All</option>@foreach (['approved','declined','blocked','failed','unknown'] as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select></div>
    <div><label for="environment">Environment</label><select id="environment" name="environment"><option value="">All</option><option @selected(request('environment') === 'sandbox')>sandbox</option><option @selected(request('environment') === 'live')>live</option></select></div>
    <input type="hidden" name="merchant_id" value="{{ request('merchant_id') }}">
    <div><button class="btn secondary" type="submit">Search</button></div>
  </form>
</section>
<section class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>Created</th><th>Transaction</th><th>Merchant</th><th>Status</th><th>State</th><th class="num">Amount</th><th>Connector</th><th>Env</th><th>Reason</th></tr></thead>
    <tbody>
    @forelse ($rows as $t)
      <tr>
        <td class="small">{{ $t->created_at->format('M j H:i:s') }}</td>
        <td><a class="mono small" href="{{ route('admin.transactions.show', $t->id) }}">{{ $t->id }}</a><div class="muted small">{{ $t->order_reference }}</div></td>
        <td>{{ $names[$t->merchant_id] ?? $t->merchant_id }}</td>
        <td>@include('partials.state', ['state' => $t->status])</td><td>@include('partials.state', ['state' => $t->state])</td>
        <td class="num">{{ money($t->amount_minor, $t->currency) }}</td><td>{{ $t->connector ?? '-' }}</td>
        <td><span class="badge {{ $t->environment }}">{{ $t->environment }}</span></td><td class="small">{{ $t->decline_category ?? $t->reason }}</td>
      </tr>
    @empty
      <tr><td colspan="9" class="muted">No transactions.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  {{ $rows->links() }}
</section>
@endsection
