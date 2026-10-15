@extends('layouts.merchant')
@section('title', 'Transactions and reports')
@section('content')
<section class="card">
  <form method="get" class="inline-form">
    <div><label for="status">Status</label><select id="status" name="status"><option value="">All</option>@foreach (['approved','declined','blocked','failed','unknown'] as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select></div>
    <div><button class="btn secondary" type="submit">Filter</button></div>
  </form>
</section>
<section class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>Created</th><th>Reference</th><th>Card</th><th>Status</th><th>State</th><th class="num">Amount</th><th class="num">Refunded</th></tr></thead>
    <tbody>
    @forelse ($rows as $t)
      <tr><td class="small">{{ $t->created_at->format('M j H:i') }}</td><td><a href="{{ route('merchant.transactions.show', $t->id) }}">{{ $t->order_reference }}</a><div class="mono small muted">{{ $t->id }}</div></td>
        <td>{{ $t->card_last4 ? ucfirst((string) $t->card_brand).' •••• '.$t->card_last4 : '-' }}</td><td>@include('partials.state', ['state' => $t->status])</td><td>@include('partials.state', ['state' => $t->state])</td>
        <td class="num">{{ money($t->amount_minor, $t->currency) }}</td><td class="num">{{ money($t->refunded_minor, $t->currency) }}</td></tr>
    @empty<tr><td colspan="7" class="muted">No transactions.</td></tr>@endforelse
    </tbody>
  </table></div>
  {{ $rows->links() }}
</section>
@endsection
