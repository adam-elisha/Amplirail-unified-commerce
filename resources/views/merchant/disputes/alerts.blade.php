@extends('layouts.merchant')
@section('title', 'Dispute alerts')
@section('content')
<section class="card">
  <h2>Fast refunds</h2>
  <p class="muted small">Alert networks (Mastercard Ethoca, Visa Verifi) warn us when a cardholder is about to dispute one of your sales. If you turn on fast refunds, we refund sales up to the amount you choose at once, and the bank cancels the dispute: no chargeback fee and no mark on your dispute ratio.</p>
  @if (in_array('payments.refund', $permissions ?? [], true))
  <form method="post" action="{{ route('merchant.alerts.settings') }}" class="inline-form">@csrf
    <div><label><input type="checkbox" name="auto_refund" value="1" @checked($setting?->auto_refund)> Refund alerts automatically</label></div>
    <div><label for="max_refund">Up to</label><input id="max_refund" name="max_refund" type="text" inputmode="decimal" value="{{ $setting ? number_format($setting->max_refund_minor / 100, 2, '.', '') : '' }}"></div>
    <div><button class="btn" type="submit">Save</button></div>
  </form>
  @else
  <p>Fast refunds are {{ $setting?->auto_refund ? 'on' : 'off' }}.</p>
  @endif
</section>
<section class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>Received</th><th>Network</th><th class="num">Amount</th><th>Outcome</th><th>Sale</th></tr></thead>
    <tbody>
    @forelse ($rows as $a)
      <tr><td class="small">{{ $a->created_at->format('M j, Y') }}</td><td class="small">{{ \App\Services\AlertResponder::PROVIDERS[$a->provider] ?? $a->provider }}</td><td class="num">{{ money($a->amount_minor, $a->currency) }}</td>
        <td>{{ str_replace('_', ' ', $a->status) }}</td><td>@if ($a->transaction_id)<a href="{{ route('merchant.transactions.show', $a->transaction_id) }}">View</a>@endif</td></tr>
    @empty
      <tr><td colspan="5" class="muted">No alerts.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  {{ $rows->links() }}
</section>
@endsection
