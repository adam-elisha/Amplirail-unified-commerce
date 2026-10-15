@extends('layouts.merchant')
@section('title', 'Disputes')
@section('content')
<section class="card">
  <p class="muted small">When a cardholder disputes a payment, the processor takes the amount back while the case is reviewed. Respond before the deadline with your evidence, or accept the chargeback.</p>
  <form method="get" class="inline-form">
    <div><label for="status">Status</label><select id="status" name="status"><option value="">All</option>@foreach (['needs_response','under_review','won','lost','accepted'] as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ str_replace('_', ' ', $s) }}</option>@endforeach</select></div>
    <div><button class="btn secondary" type="submit">Filter</button></div>
  </form>
</section>
<section class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>Opened</th><th>Reason</th><th>Status</th><th class="num">Amount</th><th>Respond by</th><th></th></tr></thead>
    <tbody>
    @forelse ($rows as $d)
      @php $left = $d->daysLeft(); @endphp
      <tr>
        <td class="small">{{ $d->created_at->format('M j, Y') }}</td>
        <td>{{ $d->categoryLabel() }}<div class="muted small mono">{{ $d->id }}</div></td>
        <td>@include('partials.state', ['state' => $d->status])</td>
        <td class="num">{{ money($d->amount_minor, $d->currency) }}</td>
        <td class="small">{{ $d->due_at?->format('M j') ?? '-' }}@if ($left !== null)<div class="{{ $left <= 3 ? 'check-bad' : 'muted' }} small">{{ $left < 0 ? 'overdue' : $left.' days left' }}</div>@endif</td>
        <td><a class="btn secondary small" href="{{ route('merchant.disputes.show', $d->id) }}">{{ $d->status === 'needs_response' ? 'Respond' : 'View' }}</a></td>
      </tr>
    @empty
      <tr><td colspan="6" class="muted">No disputes. Good news.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  {{ $rows->links() }}
</section>
@endsection
