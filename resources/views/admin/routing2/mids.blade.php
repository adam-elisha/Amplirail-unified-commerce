@extends('layouts.admin')
@section('title', 'MID management')
@section('crumb', 'Payments · Routing')
@section('content')
<section class="card">
  <p class="muted small">Caps and pauses stop new payments from using a MID; the merchant's next MID in order is tried instead. Volumes are captured sales (UTC), refreshed every minute. A merchant's payments only ever use that merchant's own MIDs.</p>
  <div class="table-wrap"><table>
    <thead><tr><th>MID</th><th>Merchant</th><th>Processor</th><th class="num">Today</th><th class="num">This month</th><th>Use of monthly cap</th>@if ($canEdit)<th>Caps and status</th>@endif</tr></thead>
    <tbody>
    @forelse ($mids as $m)
      @php $c = $controls[$m->id] ?? null; $v = $vol[$m->id] ?? null; $month = (int) ($v->month ?? 0); $pct = $c && $c->monthly_cap_minor ? min(100, (int) round($month * 100 / max(1, $c->monthly_cap_minor))) : null; @endphp
      <tr>
        <td>{{ $m->label }}<div class="mono small muted">{{ $m->provider_mid ?? $m->id }}</div>@if ($c?->paused)<span class="badge red">paused</span>@endif <span class="badge {{ $m->environment }}">{{ $m->environment }}</span></td>
        <td>{{ $names[$m->merchant_id] ?? $m->merchant_id }}</td>
        <td>{{ $m->connector }}<div class="muted small">{{ $m->status }} · priority {{ $m->priority }}</div></td>
        <td class="num">{{ money((int) ($v->today ?? 0), $m->currency) }}</td>
        <td class="num">{{ money($month, $m->currency) }}</td>
        <td class="small">@if ($pct !== null)<span class="{{ $pct >= 90 ? 'check-bad' : '' }}">{{ $pct }}% of {{ money($c->monthly_cap_minor, $m->currency) }}</span>@else no cap @endif</td>
        @if ($canEdit)
        <td>
          <form method="post" action="{{ route('admin.mids.update', $m->id) }}" class="inline-form">@csrf
            <div><input name="daily_cap" type="text" inputmode="decimal" aria-label="Daily cap" placeholder="daily cap" value="{{ $c?->daily_cap_minor !== null ? number_format($c->daily_cap_minor / 100, 2, '.', '') : '' }}"></div>
            <div><input name="monthly_cap" type="text" inputmode="decimal" aria-label="Monthly cap" placeholder="monthly cap" value="{{ $c?->monthly_cap_minor !== null ? number_format($c->monthly_cap_minor / 100, 2, '.', '') : '' }}"></div>
            <div><label class="small"><input type="checkbox" name="paused" value="1" @checked($c?->paused)> paused</label></div>
            <div><button class="btn secondary small" type="submit">Save</button></div>
          </form>
        </td>
        @endif
      </tr>
    @empty
      <tr><td colspan="7" class="muted">No MIDs yet.</td></tr>
    @endforelse
    </tbody>
  </table></div>
</section>
@endsection
