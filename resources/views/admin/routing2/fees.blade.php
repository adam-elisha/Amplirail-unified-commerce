@extends('layouts.admin')
@section('title', 'Platform fees and partner earnings')
@section('crumb', 'Money')
@section('content')
<section class="card">
  <p class="muted small">A platform fee is a partner's markup (SaaS platform, ISO or reseller) on a merchant's sales: a percentage of gross sales and/or a fixed amount per sale. It appears as its own line on the merchant's payout statement and, when the statement is issued, moves to the partner's balance. The merchant's signed fee agreement must cover it.</p>
  <div class="table-wrap"><table>
    <thead><tr><th>Partner</th><th>Merchant</th><th>Fee</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse ($rules as $r)
      <tr><td>{{ $r->partner_name }}<div class="mono small muted">{{ $r->partner_key }}</div></td><td>{{ $merchants[$r->merchant_id] ?? $r->merchant_id }}</td>
        <td>{{ number_format($r->percent_bps / 100, 2) }}% + {{ money($r->fixed_minor, $r->currency) }} per sale</td>
        <td>@include('partials.state', ['state' => $r->status === 'active' ? 'active' : 'suspended'])</td>
        <td>@if ($canEdit)<form method="post" action="{{ route('admin.platform-fees.toggle', $r->id) }}">@csrf<button class="btn secondary small" type="submit">{{ $r->status === 'active' ? 'Pause' : 'Resume' }}</button></form>@endif</td></tr>
    @empty
      <tr><td colspan="5" class="muted">No platform fees.</td></tr>
    @endforelse
    </tbody>
  </table></div>
</section>
<div class="grid g2">
  <section class="card">
    <h2>Owed to partners</h2>
    <div class="table-wrap"><table><thead><tr><th>Partner</th><th>Env</th><th class="num">Owed</th></tr></thead><tbody>
      @forelse ($earnings as $e)<tr><td class="mono">{{ $e['partner_key'] }}</td><td><span class="badge {{ $e['environment'] }}">{{ $e['environment'] }}</span></td><td class="num">{{ money($e['owed_minor'], $e['currency']) }}</td></tr>
      @empty<tr><td colspan="3" class="muted">Nothing owed yet. Balances appear when statements with platform fees are issued.</td></tr>@endforelse
    </tbody></table></div>
  </section>
  @if ($canEdit)
  <section class="card">
    <h2>Add a platform fee</h2>
    <form method="post" action="{{ route('admin.platform-fees.store') }}">@csrf
      <label for="pf_merchant">Merchant</label><select id="pf_merchant" name="merchant_id">@foreach ($merchants as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
      <div class="grid g2">
        <div><label for="partner_name">Partner name</label><input id="partner_name" name="partner_name" type="text" required maxlength="120"></div>
        <div><label for="partner_key">Partner code</label><input id="partner_key" name="partner_key" type="text" required maxlength="60" placeholder="acme_pay"></div>
        <div><label for="percent">Percent of sales</label><input id="percent" name="percent" type="number" step="0.01" min="0" max="10" placeholder="0.25"></div>
        <div><label for="fixed">Fixed per sale</label><input id="fixed" name="fixed" type="text" inputmode="decimal" placeholder="0.10"></div>
        <div><label for="pf_currency">Currency</label><input id="pf_currency" name="currency" type="text" maxlength="3" required value="USD"></div>
      </div>
      <button class="btn" type="submit">Add fee</button>
    </form>
  </section>
  @endif
</div>
@endsection
