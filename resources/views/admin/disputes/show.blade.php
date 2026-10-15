@extends('layouts.admin')
@section('title', 'Dispute')
@section('crumb', 'Payments · Disputes')
@section('content')
@php $can = fn (string $p) => in_array($p, $permissions ?? [], true); $left = $d->daysLeft(); @endphp
<div class="grid g3">
  <div class="span2 grid">
    <section class="card">
      <div class="card-head">
        <div><h2 class="mono">{{ $d->id }}</h2><div class="muted small">{{ $merchant?->displayName() }} · case {{ $d->network_case_id }}</div></div>
        <div>@include('partials.state', ['state' => $d->status]) <span class="badge {{ $d->environment }}">{{ $d->environment }}</span></div>
      </div>
      <div class="grid g4">
        <div class="stat"><div class="label">Amount</div><div class="value">{{ money($d->amount_minor, $d->currency) }}</div></div>
        <div class="stat"><div class="label">Stage</div><div class="value">{{ ucfirst(str_replace('_', ' ', $d->stage)) }}</div></div>
        <div class="stat"><div class="label">Reason</div><div class="value small">{{ $d->categoryLabel() }}</div><div class="muted small">{{ $d->reason_code ? 'code '.$d->reason_code : '' }}</div></div>
        <div class="stat"><div class="label">Respond by</div><div class="value">{{ $d->due_at?->format('M j') ?? '-' }}</div><div class="{{ ($left ?? 99) <= 3 ? 'check-bad' : 'muted' }} small">{{ $left === null ? '' : ($left < 0 ? 'overdue' : $left.' days left') }}</div></div>
      </div>
      <dl class="kv">
        <dt>Transaction</dt><dd>@if ($txn)<a class="mono" href="{{ route('admin.transactions.show', $txn->id) }}">{{ $txn->id }}</a> · {{ money($txn->captured_minor, $txn->currency) }} captured · order {{ $txn->order_reference }}@else <span class="mono">{{ $d->transaction_id }}</span>@endif</dd>
        <dt>Card</dt><dd>{{ $txn && $txn->card_last4 ? ucfirst((string) $txn->card_brand).' ending '.$txn->card_last4 : '-' }}</dd>
        <dt>Opened</dt><dd>{{ $d->created_at->format('M j, Y H:i') }} by <span class="mono small">{{ $d->opened_by }}</span></dd>
        @if ($d->resolved_at)<dt>Closed</dt><dd>{{ $d->resolved_at->format('M j, Y H:i') }} by <span class="mono small">{{ $d->resolved_by }}</span>{{ $d->resolution_note ? ' · '.$d->resolution_note : '' }}</dd>@endif
      </dl>
    </section>

    <section class="card">
      <h2>Merchant response</h2>
      @if ($d->merchant_response)
        <p style="white-space:pre-wrap">{{ $d->merchant_response }}</p>
        <div class="muted small">Submitted {{ $d->responded_at?->format('M j, Y H:i') }}</div>
        @if (! empty($d->evidence))
          <div class="table-wrap"><table><thead><tr><th>Evidence</th><th>Description</th></tr></thead><tbody>
            @foreach ($d->evidence as $e)<tr><td>{{ ucfirst(str_replace('_', ' ', $e['type'])) }}</td><td class="small">{{ $e['description'] }}</td></tr>@endforeach
          </tbody></table></div>
        @endif
      @else
        <p class="muted">No response yet.</p>
      @endif
    </section>

    <section class="card">
      <h2>Ledger</h2><div class="muted small">Append-only double entry. A chargeback reduces what the merchant is owed; a win reverses it.</div>
      <div class="table-wrap"><table><thead><tr><th>Journal</th><th>Account</th><th class="num">Amount</th></tr></thead><tbody>
        @forelse ($ledger as $p)<tr><td class="mono small">{{ $p['journal_id'] }}</td><td class="mono small">{{ $p['account'] }}</td><td class="num">{{ money($p['amount_minor'], $p['currency']) }}</td></tr>
        @empty<tr><td colspan="3" class="muted">No money moved (inquiry, or a sandbox payment without a MID).</td></tr>@endforelse
      </tbody></table></div>
    </section>

    <section class="card">
      <h2>Event timeline</h2>
      <div class="timeline">
        @foreach ($events as $e)
          <div><span class="mono small">{{ \Illuminate\Support\Carbon::parse($e['occurred_at'])->format('M j H:i:s') }}</span><span>{{ $e['type'] }}<div class="muted small mono">{{ $e['id'] }}</div></span></div>
        @endforeach
      </div>
    </section>
  </div>

  <div class="grid">
    @if ($can('payments.refund') && $d->isOpen())
      <section class="card">
        <h2>Record the outcome</h2>
        <p class="muted small">Use the processor's decision. Won returns the funds to the merchant's balance.</p>
        <form method="post" action="{{ route('admin.disputes.resolve', $d->id) }}">@csrf
          <label for="note">Note</label><input id="note" name="note" type="text" maxlength="2000">
          <div class="actions">
            <button class="btn" type="submit" name="outcome" value="won">Won</button>
            <button class="btn danger" type="submit" name="outcome" value="lost">Lost</button>
          </div>
        </form>
      </section>
      @if ($d->status === 'needs_response')
        <section class="card">
          <h2>Respond for the merchant</h2>
          <form method="post" action="{{ route('admin.disputes.respond', $d->id) }}">@csrf
            @include('merchant.disputes.partials.evidence')
            <button class="btn full" type="submit">Submit response</button>
          </form>
        </section>
      @endif
    @endif
  </div>
</div>
@endsection
