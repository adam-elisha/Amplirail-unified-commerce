@extends('layouts.admin')
@section('title', 'Reserves')
@section('crumb', 'Money')
@section('content')
<section class="card">
  <p class="muted small">Reserves are held back on each issued payout statement according to the merchant's fee schedule. Releasing a reserve needs a second person with fee approval rights; the money is added to the merchant's next payout.</p>
  <div class="table-wrap"><table>
    <thead><tr><th>Merchant</th><th>Environment</th><th class="num">Held</th><th>Last change</th>@if ($canRequest)<th>Release</th>@endif</tr></thead>
    <tbody>
    @forelse ($rows as $r)
      <tr>
        <td>{{ $names[$r['merchant_id']] ?? $r['merchant_id'] }}<div class="mono small muted">{{ $r['merchant_id'] }}</div></td>
        <td><span class="badge {{ $r['environment'] }}">{{ $r['environment'] }}</span></td>
        <td class="num">{{ money($r['held_minor'], $r['currency']) }}</td>
        <td class="small">{{ $r['last_at'] ? \Illuminate\Support\Carbon::parse($r['last_at'])->format('M j, Y') : '-' }}</td>
        @if ($canRequest)
        <td>
          <form method="post" action="{{ route('admin.reserves.release') }}" class="inline-form">@csrf
            <input type="hidden" name="merchant_id" value="{{ $r['merchant_id'] }}"><input type="hidden" name="currency" value="{{ $r['currency'] }}"><input type="hidden" name="environment" value="{{ $r['environment'] }}">
            <div><input name="amount" type="text" inputmode="decimal" required aria-label="Amount" value="{{ number_format($r['held_minor'] / 100, 2, '.', '') }}"></div>
            <div><input name="reason" type="text" required minlength="5" maxlength="500" placeholder="Reason" aria-label="Reason"></div>
            <div><button class="btn secondary small" type="submit">Request release</button></div>
          </form>
        </td>
        @endif
      </tr>
    @empty
      <tr><td colspan="5" class="muted">No reserves held. Reserves appear after payout statements with a reserve are issued.</td></tr>
    @endforelse
    </tbody>
  </table></div>
</section>
@endsection
