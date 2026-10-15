@extends('layouts.admin')
@section('title', 'Chargeback outcomes')
@section('crumb', 'Payments · Disputes')
@section('content')
@php $rate = fn ($r) => ($r->won + $r->lost) > 0 ? round($r->won * 100 / ($r->won + $r->lost)).'%' : '-'; @endphp
<section class="card">
  <form method="get" class="inline-form">
    <div><label for="days">Period</label><select id="days" name="days">@foreach ([30, 90, 180, 365] as $d)<option value="{{ $d }}" @selected($days === $d)>Last {{ $d }} days</option>@endforeach</select></div>
    <div><button class="btn secondary" type="submit">Show</button></div>
  </form>
  <p class="muted small">{{ $total }} chargebacks opened in the period; {{ $responded }} answered with evidence ({{ $total > 0 ? round($responded * 100 / $total) : 0 }}%). Win rate counts closed cases only (won ÷ won + lost + accepted).</p>
</section>
@foreach (['By reason' => [$byReason, fn ($k) => $categories[$k] ?? $k], 'By merchant' => [$byMerchant, fn ($k) => $names[$k] ?? $k]] as $title => [$rows, $name])
<section class="card">
  <h2>{{ $title }}</h2>
  <div class="table-wrap"><table>
    <thead><tr><th></th><th class="num">Cases</th><th class="num">Open</th><th class="num">Won</th><th class="num">Lost</th><th class="num">Win rate</th><th class="num">Disputed</th><th class="num">Recovered</th></tr></thead>
    <tbody>
    @forelse ($rows as $r)
      <tr><td>{{ $name($r->k) }}</td><td class="num">{{ $r->total }}</td><td class="num">{{ $r->open }}</td><td class="num">{{ $r->won }}</td><td class="num">{{ $r->lost }}</td><td class="num">{{ $rate($r) }}</td><td class="num">{{ money((int) $r->disputed, $r->currency) }}</td><td class="num">{{ money((int) $r->recovered, $r->currency) }}</td></tr>
    @empty
      <tr><td colspan="8" class="muted">No chargebacks in this period.</td></tr>
    @endforelse
    </tbody>
  </table></div>
</section>
@endforeach
@endsection
