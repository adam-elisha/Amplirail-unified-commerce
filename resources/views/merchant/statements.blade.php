@extends('layouts.merchant')
@section('title', 'Payout statements')
@section('content')
<section class="card">
  <p class="muted small">Every line comes from your signed fee schedule. Each line is rounded half-up and totals are the sum of lines.</p>
  <div class="table-wrap"><table><thead><tr><th>Period</th><th>MID</th><th class="num">Gross</th><th class="num">Fees</th><th class="num">Reserve</th><th class="num">Net payout</th><th>Download</th></tr></thead><tbody>
    @forelse ($rows as $s)
      <tr><td class="small">{{ $s->period_start->toDateString() }} to {{ $s->period_end->toDateString() }}</td><td class="mono small">{{ $s->mid_label }}</td>
        <td class="num">{{ money($s->gross_minor, $s->currency) }}</td><td class="num">{{ money($s->fees_minor, $s->currency) }}</td><td class="num">{{ money($s->reserve_minor, $s->currency) }}</td>
        <td class="num"><strong>{{ money($s->net_minor, $s->currency) }}</strong></td>
        <td class="small">@foreach (['pdf','csv','xlsx'] as $f)<a href="{{ route('merchant.statements.download', [$s->id, $f]) }}">{{ strtoupper($f) }}</a> @endforeach</td></tr>
    @empty<tr><td colspan="7" class="muted">No statements issued yet.</td></tr>@endforelse
  </tbody></table></div>
  {{ $rows->links() }}
</section>
@endsection
