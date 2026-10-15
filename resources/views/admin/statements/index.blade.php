@extends('layouts.admin')
@section('title', 'Payout statements')
@section('crumb', 'Money')
@section('content')
@php $can = fn (string $p) => in_array($p, $permissions ?? [], true); @endphp
@if ($can('fees.manage'))
<section class="card">
  <h2>Statement run</h2>
  <p class="muted small">Weekly runs happen automatically every Monday 06:00 UTC. Only merchants with a signed fee agreement are included. Drafts are issued through Approvals by a second person.</p>
  <form method="post" action="{{ route('admin.statements.generate') }}" class="inline-form">@csrf
    <div><label>From</label><input name="from" type="date" value="{{ $defaultFrom }}" required></div>
    <div><label>To</label><input name="to" type="date" value="{{ $defaultTo }}" required></div>
    <div><label>Environment</label><select name="environment"><option>live</option><option>sandbox</option></select></div>
    <div><button class="btn" type="submit">Generate drafts</button></div>
  </form>
</section>
@endif
<section class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>Period</th><th>Merchant</th><th>MID</th><th class="num">Gross</th><th class="num">Fees</th><th class="num">Reserve</th><th class="num">Net payout</th><th>Status</th><th>Downloads</th><th></th></tr></thead>
    <tbody>
    @forelse ($rows as $s)
      <tr><td class="small">{{ $s->period_start->toDateString() }} to {{ $s->period_end->toDateString() }}</td><td>{{ $names[$s->merchant_id] ?? $s->merchant_id }}</td><td class="mono small">{{ $s->mid_label }}</td>
        <td class="num">{{ money($s->gross_minor, $s->currency) }}</td><td class="num">{{ money($s->fees_minor, $s->currency) }}</td><td class="num">{{ money($s->reserve_minor, $s->currency) }}</td>
        <td class="num"><strong>{{ money($s->net_minor, $s->currency) }}</strong></td><td>@include('partials.state', ['state' => $s->status])</td>
        <td class="small">@foreach (['pdf','csv','xlsx'] as $f)<a href="{{ route('admin.statements.download', [$s->id, $f]) }}">{{ strtoupper($f) }}</a> @endforeach</td>
        <td>@if ($s->status === 'draft' && $can('fees.manage'))<form method="post" action="{{ route('admin.statements.submit', $s->id) }}">@csrf<button class="btn secondary small" type="submit">Approve and issue</button></form>@endif</td></tr>
    @empty
      <tr><td colspan="10" class="muted">No statements yet.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  {{ $rows->links() }}
</section>
@endsection
