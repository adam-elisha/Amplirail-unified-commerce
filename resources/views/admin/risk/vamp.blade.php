@extends('layouts.admin')
@section('title', 'Network monitoring (Visa VAMP)')
@section('crumb', 'Risk')
@section('content')
@php
  $fmt = fn (int $bps) => \Modules\Monitoring\Domain\VampCalculator::format($bps);
  $badge = ['ok' => 'green', 'warning' => 'amber', 'over_ratio_below_minimum' => 'amber', 'excessive' => 'red', 'above_standard' => 'amber'];
  $label = ['ok' => 'OK', 'warning' => 'Early warning', 'over_ratio_below_minimum' => 'Over ratio, under 1,500 events', 'excessive' => 'Excessive', 'above_standard' => 'Above standard'];
@endphp
<section class="card">
  <form method="get" class="inline-form">
    <div><label for="month">Month</label><input id="month" name="month" type="month" value="{{ $month->format('Y-m') }}"></div>
    <div><label for="environment">Environment</label><select id="environment" name="environment"><option value="live" @selected($env === 'live')>live</option><option value="sandbox" @selected($env === 'sandbox')>sandbox</option></select></div>
    <div><label for="region">Merchant region</label><select id="region" name="region"><option value="us_ca_eu_ap" @selected($region === 'us_ca_eu_ap')>US, Canada, EU, Asia Pacific (1.5%)</option><option value="other" @selected($region === 'other')>Other regions (2.2%)</option></select></div>
    <div><button class="btn secondary" type="submit">Show</button></div>
  </form>
  <p class="muted small">Ratio = (Visa TC40 fraud reports + disputes) ÷ settled card-not-present Visa sales in the month; a sale with both counts once. A merchant is excessive at {{ $fmt($threshold) }} or more with at least 1,500 events. Early warning starts at 75% of the threshold. Thresholds come from Visa's published program; confirm them with the acquirer. The response to a breach is a remediation plan, tighter screening or suspension through Approvals, never moving sales to other MIDs.</p>
</section>
<div class="grid g4">
  <section class="card stat"><div class="label">Portfolio ratio</div><div class="value">{{ $fmt($portfolio['ratio_bps']) }}</div><span class="badge {{ $badge[$portfolio['status']] }}">{{ $label[$portfolio['status']] }}</span></section>
  <section class="card stat"><div class="label">Fraud + dispute events</div><div class="value">{{ number_format($portfolio['count']) }}</div></section>
  <section class="card stat"><div class="label">Settled card-not-present Visa sales</div><div class="value">{{ number_format($portfolio['settled_cnp']) }}</div></section>
  <section class="card stat"><div class="label">Acquirer thresholds</div><div class="value small">0.50% above standard · 0.70% excessive</div></section>
</div>
<section class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>Merchant</th><th class="num">CNP Visa sales</th><th class="num">TC40</th><th class="num">Disputes</th><th class="num">Events</th><th class="num">Ratio</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse ($rows as $r)
      <tr>
        <td>{{ $names[$r['merchant_id']] ?? $r['merchant_id'] }}</td>
        <td class="num">{{ number_format($r['settled_cnp']) }}</td><td class="num">{{ number_format($r['tc40']) }}</td><td class="num">{{ number_format($r['tc15']) }}</td>
        <td class="num">{{ number_format($r['count']) }}</td><td class="num">{{ $fmt($r['ratio_bps']) }}</td>
        <td><span class="badge {{ $badge[$r['status']] }}">{{ $label[$r['status']] }}</span></td>
        <td><a class="btn secondary small" href="{{ route('admin.merchants.show', $r['merchant_id']) }}">Merchant</a></td>
      </tr>
    @empty
      <tr><td colspan="8" class="muted">No Visa card-not-present activity this month.</td></tr>
    @endforelse
    </tbody>
  </table></div>
</section>
@if ($canImport)
<section class="card">
  <h2>Import TC40 / SAFE fraud reports</h2>
  <p class="muted small">CSV with a header row: <span class="mono">network, report_type, network_ref, report_date, transaction_id, amount, fraud_type</span>. network is visa or mastercard; report_type is tc40 or safe; transaction_id is the AmpliRail ID or the processor's reference. Never include card numbers. A report already imported is skipped.</p>
  <form method="post" action="{{ route('admin.risk.vamp.import') }}" enctype="multipart/form-data">@csrf
    <label for="file">CSV file</label><input id="file" name="file" type="file" accept=".csv,text/csv" required>
    <button class="btn" type="submit">Import</button>
  </form>
</section>
@endif
@endsection
