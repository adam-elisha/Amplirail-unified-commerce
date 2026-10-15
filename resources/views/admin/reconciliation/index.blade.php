@extends('layouts.admin')
@section('title', 'Reconciliation')
@section('crumb', 'Money')
@section('content')
<div class="grid g3">
  <section class="card stat"><div class="label">Open exceptions</div><div class="value">{{ $exceptions }}</div><div class="muted small">Deposits that differ from what we expected</div></section>
  <section class="card span2">
    <h2>How it works</h2>
    <p class="muted small">For each processor deposit we compare the amount received with what AmpliRail expected for that MID and period: captured sales, minus refunds, minus chargebacks, plus chargebacks won. Any difference, even one cent, is an exception until Finance explains it.</p>
  </section>
</div>
<section class="card">
  <form method="get" class="inline-form">
    <div><label for="status">Status</label><select id="status" name="status"><option value="">All</option>@foreach (['exception','matched','resolved'] as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select></div>
    <div><label for="merchant_account_id">MID</label><select id="merchant_account_id" name="merchant_account_id"><option value="">All</option>@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected(request('merchant_account_id') === $a->id)>{{ $a->label }} · {{ $accountMerchants[$a->merchant_id] ?? '' }}</option>@endforeach</select></div>
    <div><button class="btn secondary" type="submit">Filter</button></div>
  </form>
</section>
<section class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>Deposit date</th><th>Merchant / MID</th><th>Period</th><th>Reference</th><th class="num">Expected</th><th class="num">Received</th><th class="num">Difference</th><th>Status</th></tr></thead>
    <tbody>
    @forelse ($rows as $d)
      <tr>
        <td class="small">{{ $d->deposit_date->format('M j, Y') }}<div class="muted small">{{ $d->source }}</div></td>
        <td>{{ $names[$d->merchant_id] ?? $d->merchant_id }}<div class="muted small">{{ $d->mid_label }} · <span class="badge {{ $d->environment }}">{{ $d->environment }}</span></div></td>
        <td class="small">{{ $d->period_start->format('M j') }} to {{ $d->period_end->format('M j') }}</td>
        <td class="mono small">{{ $d->reference }}</td>
        <td class="num">{{ money($d->expected_minor, $d->currency) }}</td>
        <td class="num">{{ money($d->received_minor, $d->currency) }}</td>
        <td class="num {{ $d->difference_minor !== 0 ? 'check-bad' : '' }}">{{ money($d->difference_minor, $d->currency) }}</td>
        <td><span class="badge {{ ['matched' => 'green', 'exception' => 'amber', 'resolved' => 'grey'][$d->status] ?? '' }}">{{ $d->status }}</span>
          @if ($d->status === 'exception' && $canResolve)
            <form method="post" action="{{ route('admin.recon.resolve', $d->id) }}" class="inline-form">@csrf
              <div><input name="note" type="text" required minlength="5" maxlength="2000" placeholder="Explain the difference" aria-label="Explanation"></div>
              <div><button class="btn secondary small" type="submit">Close</button></div>
            </form>
          @elseif ($d->resolution_note)<div class="muted small">{{ $d->resolution_note }}</div>@endif
        </td>
      </tr>
    @empty
      <tr><td colspan="8" class="muted">No deposits recorded yet.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  {{ $rows->links() }}
</section>
@if ($canResolve)
<div class="grid g2">
  <section class="card">
    <h2>Record a deposit</h2>
    <form method="post" action="{{ route('admin.recon.store') }}">@csrf
      <label for="mid">MID</label><select id="mid" name="merchant_account_id" required>@foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->label }} · {{ $accountMerchants[$a->merchant_id] ?? '' }} · {{ $a->currency }} · {{ $a->environment }}</option>@endforeach</select>
      <div class="grid g3">
        <div><label for="deposit_date">Deposit date</label><input id="deposit_date" name="deposit_date" type="date" required value="{{ old('deposit_date', now()->toDateString()) }}"></div>
        <div><label for="period_start">Sales from</label><input id="period_start" name="period_start" type="date" required value="{{ old('period_start') }}"></div>
        <div><label for="period_end">Sales to</label><input id="period_end" name="period_end" type="date" required value="{{ old('period_end') }}"></div>
      </div>
      <div class="grid g2">
        <div><label for="amount">Amount received</label><input id="amount" name="amount" type="text" inputmode="decimal" required value="{{ old('amount') }}"></div>
        <div><label for="reference">Deposit reference</label><input id="reference" name="reference" type="text" required maxlength="120" value="{{ old('reference') }}"></div>
      </div>
      <button class="btn" type="submit">Record and compare</button>
    </form>
  </section>
  <section class="card">
    <h2>Import a CSV</h2>
    <p class="muted small">One row per deposit, with a header row: <span class="mono">mid, deposit_date, period_start, period_end, amount, reference</span>. The MID can be the AmpliRail label, the processor MID or the AmpliRail ID. Up to 500 rows. A reference already recorded for the same MID is skipped.</p>
    <form method="post" action="{{ route('admin.recon.import') }}" enctype="multipart/form-data">@csrf
      <label for="file">CSV file</label><input id="file" name="file" type="file" accept=".csv,text/csv" required>
      <button class="btn" type="submit">Import and compare</button>
    </form>
  </section>
</div>
@endif
@endsection
