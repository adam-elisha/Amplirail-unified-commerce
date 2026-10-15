@extends('layouts.admin')
@section('title', 'Approvals')
@section('content')
<section class="card">
  <h2>Dual-control queue</h2>
  <p class="muted small">You cannot approve your own request. Approving needs the type's permission, your authenticator code (or an emailed code if you have no authenticator app) and a note. Every decision is audited.</p>
  <form method="post" action="{{ route('admin.step-up.send') }}">@csrf<button class="btn secondary small" type="submit">Email me a code</button></form>
  @forelse ($pending as $a)
    <article class="card">
      <div class="card-head">
        <div>
          <h3>{{ str_replace('_', ' ', ucfirst($a->type)) }} · {{ $a->payload['merchant'] ?? $a->payload['merchant_account'] ?? $a->subject_id }}</h3>
          <div class="muted small">Requested {{ $a->requested_at->diffForHumans() }} by <span class="mono">{{ $a->requested_by }}</span> · {{ $a->subject_type }} <span class="mono">{{ $a->subject_id }}</span></div>
          @if ($a->reason)<p><strong>Reason:</strong> {{ $a->reason }}</p>@endif
        </div>
        @if ($a->type === 'go_live_override')<span class="badge red">Emergency override</span>@endif
      </div>
      @if (isset($a->payload['checks']))
        <div class="grid g2">
          @foreach ($a->payload['checks'] as $c)
            <div class="{{ $c['passed'] ? 'check-ok' : 'check-bad' }}">{{ $c['label'] }}</div>
          @endforeach
        </div>
      @elseif (isset($a->payload['amount_minor']))
        <p>Release {{ money($a->payload['amount_minor'], $a->payload['currency']) }} from reserve · {{ $a->payload['environment'] ?? '' }}</p>
      @elseif (isset($a->payload['net_minor']))
        <p>Net payout {{ money($a->payload['net_minor'], $a->payload['currency']) }} · {{ $a->payload['period'] }}</p>
      @elseif (isset($a->payload['after']))
        <details><summary>Rule changes (version {{ $a->payload['version'] }}, effective {{ $a->payload['effective_from'] }})</summary><pre class="mono small">{{ json_encode($a->payload['after'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
      @endif
      @if ($a->requested_by === $me)
        <p class="alert info small">You requested this item. A different authorized person must decide it.</p>
      @else
        <form method="post" action="{{ route('admin.approvals.approve', $a->id) }}" class="inline-form">
          @csrf
          <div><label for="code-{{ $a->id }}">Authenticator or emailed code</label><input id="code-{{ $a->id }}" name="step_up_code" inputmode="numeric" maxlength="8" required autocomplete="one-time-code"></div>
          <div><label for="note-{{ $a->id }}">Note (required)</label><input id="note-{{ $a->id }}" name="note" type="text" required minlength="5"></div>
          <div><button class="btn" type="submit">Approve</button></div>
        </form>
        <form method="post" action="{{ route('admin.approvals.reject', $a->id) }}" class="inline-form">
          @csrf
          <div><label for="rnote-{{ $a->id }}">Rejection note</label><input id="rnote-{{ $a->id }}" name="note" type="text" required minlength="5"></div>
          <div><button class="btn secondary" type="submit">Reject</button></div>
        </form>
      @endif
    </article>
  @empty
    <p class="muted">Nothing waiting for approval.</p>
  @endforelse
</section>

<section class="card">
  <h2>Recent decisions</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Type</th><th>Subject</th><th>Status</th><th>Requested by</th><th>Decided by</th><th>Note</th><th>When</th></tr></thead>
    <tbody>
    @forelse ($decided as $a)
      <tr><td>{{ str_replace('_', ' ', $a->type) }}</td><td class="mono small">{{ $a->subject_id }}</td><td>@include('partials.state', ['state' => $a->status])</td>
        <td class="mono small">{{ $a->requested_by }}</td><td class="mono small">{{ $a->decided_by }}</td><td>{{ $a->decision_note }}@if (isset($a->result['error']))<div class="small muted">{{ $a->result['error'] }}</div>@endif</td>
        <td class="small">{{ $a->decided_at?->format('M j H:i') }}</td></tr>
    @empty
      <tr><td colspan="7" class="muted">No decisions yet.</td></tr>
    @endforelse
    </tbody>
  </table></div>
</section>
@endsection
