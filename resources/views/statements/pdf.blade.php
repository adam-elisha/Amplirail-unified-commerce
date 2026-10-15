<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Payout statement {{ $s->id }}</title>
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0B1F4B; }
  h1 { font-size: 18px; margin: 0 0 4px; }
  .muted { color: #5b6b8c; }
  table { width: 100%; border-collapse: collapse; margin-top: 16px; }
  th, td { padding: 6px 4px; border-bottom: 1px solid #dde3ef; text-align: left; }
  td.num, th.num { text-align: right; }
  tr.total td { font-weight: bold; border-top: 2px solid #0B1F4B; }
</style>
</head>
<body>
  <h1>AmpliRail payout statement</h1>
  <div class="muted">{{ $s->id }} · {{ $s->mid_label }} · {{ $s->period_start->toDateString() }} to {{ $s->period_end->toDateString() }} · fee schedule {{ $s->fee_schedule_version }}</div>
  <table>
    <thead><tr><th>Section</th><th>Line</th><th class="num">Amount ({{ $s->currency }})</th></tr></thead>
    <tbody>
      <tr><td>activity</td><td>Gross sales ({{ $s->input['approvedCount'] }} approved)</td><td class="num">{{ \AmpliRail\Money\Money::of($s->gross_minor, $s->currency)->format() }}</td></tr>
      @foreach ($s->lines as $l)
        <tr><td>{{ $l['section'] }}</td><td>{{ $l['label'] }}</td><td class="num">{{ \AmpliRail\Money\Money::of((int) $l['amount_minor'], $s->currency)->format() }}</td></tr>
      @endforeach
      <tr class="total"><td>total</td><td>Net payout</td><td class="num">{{ \AmpliRail\Money\Money::of($s->net_minor, $s->currency)->format() }}</td></tr>
    </tbody>
  </table>
  <p class="muted">Total fees {{ \AmpliRail\Money\Money::of($s->fees_minor, $s->currency)->format() }} · reserve withheld {{ \AmpliRail\Money\Money::of($s->reserve_minor, $s->currency)->format() }} · chargeback ratio {{ number_format($s->cb_ratio_bps / 100, 2) }}%</p>
  <p class="muted">Each line is rounded half-up; totals are the sum of lines. Issued statements are never changed; corrections appear as adjustment lines on the next statement.</p>
</body>
</html>
