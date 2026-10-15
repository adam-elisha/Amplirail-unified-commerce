@extends('layouts.merchant')
@section('title', 'Developers and API keys')
@section('content')
<section class="card">
  <h2>API keys</h2>
  <p class="muted small">Secret keys are shown once. Store them in your secrets manager, never in code. Base URL: <code>{{ rtrim(config('app.url'), '/') }}/v1</code>. Every POST needs an <code>Idempotency-Key</code> header.</p>
  <div class="table-wrap"><table><thead><tr><th>Environment</th><th>Type</th><th>Key</th><th>Last used</th><th></th></tr></thead><tbody>
    @foreach ($keys as $k)
      <tr><td><span class="badge {{ $k->environment === 'live' ? 'live' : 'sandbox' }}">{{ $k->environment }}</span></td><td>{{ $k->type }}</td><td class="mono small">{{ $k->display_prefix }}</td><td class="small">{{ $k->last_used_at?->diffForHumans() ?? 'Never' }}</td>
        <td class="actions">
          @if ($k->type === 'secret' && $k->getRawOriginal('pending_reveal') !== null)
            <form method="post" action="{{ route('merchant.keys.reveal', $k->id) }}">@csrf<button class="btn small" type="submit">Reveal once</button></form>
          @endif
          <form method="post" action="{{ route('merchant.keys.roll', $k->id) }}" data-confirm="Roll this key? The old key stops working immediately.">@csrf<button class="btn secondary small" type="submit">Roll</button></form>
        </td></tr>
    @endforeach
  </tbody></table></div>
</section>
<section class="card">
  <h2>Webhooks ({{ $env }})</h2>
  <p class="muted small">Signed with <code>AmpliRail-Signature: t=&lt;unix&gt;,v1=&lt;HMAC-SHA256 of "t.body"&gt;</code>. Reject signatures older than 5 minutes. Failed deliveries retry for up to 72 hours.</p>
  <form method="post" action="{{ route('merchant.webhooks.store') }}">@csrf
    <label>Endpoint URL (HTTPS)</label><input name="url" type="url" required placeholder="https://example.com/amplirail/webhooks">
    <fieldset class="actions"><legend class="muted small">Events</legend>
      @foreach ($events as $e)<label><input type="checkbox" name="events[]" value="{{ $e }}" @checked(in_array($e, ['transaction.approved', 'refund.succeeded', 'webhook.test'], true))> {{ $e }}</label>@endforeach
    </fieldset>
    <button class="btn" type="submit">Add endpoint</button>
  </form>
  <div class="rows">
    @foreach ($endpoints as $ep)
      <div><span class="small">{{ $ep->url }} <span class="badge {{ $ep->environment === 'live' ? 'live' : 'sandbox' }}">{{ $ep->environment }}</span> @include('partials.state', ['state' => $ep->status])</span>
        <form method="post" action="{{ route('merchant.webhooks.test', $ep->id) }}">@csrf<button class="btn secondary small" type="submit">Send test event</button></form></div>
    @endforeach
  </div>
</section>
<section class="card">
  <h2>Recent deliveries</h2>
  <div class="table-wrap"><table><thead><tr><th>When</th><th>Event</th><th>Status</th><th>Attempts</th><th>Last result</th></tr></thead><tbody>
    @forelse ($deliveries as $d)
      <tr><td class="small">{{ $d->created_at->format('M j H:i:s') }}</td><td>{{ $d->event_type }}</td><td>@include('partials.state', ['state' => $d->status])</td><td>{{ $d->attempts }}</td><td class="small">{{ $d->last_status_code ?? $d->last_error }}</td></tr>
    @empty<tr><td colspan="5" class="muted">No deliveries yet.</td></tr>@endforelse
  </tbody></table></div>
</section>
@endsection
