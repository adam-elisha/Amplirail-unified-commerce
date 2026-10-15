@extends('layouts.admin')
@section('title', 'Routing and eligibility')
@section('crumb', 'Payments')
@section('content')
@php $can = fn (string $p) => in_array($p, $permissions ?? [], true); @endphp
<section class="card">
  <h2>Processor eligibility rules</h2>
  <p class="muted small">Versioned and effective-dated. A new version is drafted, submitted to Approvals and published only by a second person with routing.publish. The current effective version is v{{ $current?->version ?? '-' }}.</p>
  <div class="table-wrap"><table>
    <thead><tr><th>Version</th><th>Status</th><th>Effective from</th><th>Notes</th><th>Created by</th><th></th></tr></thead>
    <tbody>
    @foreach ($sets as $s)
      <tr><td>v{{ $s->version }}{{ $current && $current->id === $s->id ? ' (current)' : '' }}</td><td>@include('partials.state', ['state' => $s->status])</td>
        <td class="small">{{ $s->effective_from?->format('M j, Y H:i') ?? '-' }}</td><td>{{ $s->notes }}</td><td class="mono small">{{ $s->created_by }}</td>
        <td>@if ($s->status === 'draft' && $can('routing.draft'))
          <form method="post" action="{{ route('admin.routing.submit', $s->id) }}" class="actions">@csrf<input name="effective_from" type="date" value="{{ now()->toDateString() }}"><button class="btn secondary small" type="submit">Submit for approval</button></form>
        @endif</td></tr>
    @endforeach
    </tbody>
  </table></div>
</section>
@if ($can('routing.draft'))
<section class="card">
  <h2>Draft a new version</h2>
  <p class="muted small">Route rule keys: countries, regions_blocked, mcc_blocked, mcc_restricted, allow_restricted, channels, card_present, recurring_allowed, max_monthly_volume_minor, max_ticket_minor, max_chargeback_ratio_bps, currencies, min_reserve_bps, required_documents.</p>
  <form method="post" action="{{ route('admin.routing.draft') }}">@csrf
    <label for="rules">Rules (JSON)</label><textarea id="rules" name="rules" rows="18">{{ old('rules', $json) }}</textarea>
    <label for="notes">What changed and why</label><input id="notes" name="notes" type="text" required>
    <button class="btn" type="submit">Save draft</button>
  </form>
</section>
@endif
<section class="card">
  <h2>Connector catalog</h2>
  <div class="table-wrap"><table><thead><tr><th>Code</th><th>Name</th><th>Enabled</th><th>Environments</th><th>Currencies</th><th>Channels</th></tr></thead><tbody>
    @foreach ($connectors as $code => $c)
      @continue(! is_array($c) || ! isset($c['environments']))
      <tr><td class="mono">{{ $code }}</td><td>{{ $c['name'] }}</td><td>{{ ($c['enabled'] ?? false) ? 'Yes' : 'No' }}</td><td>{{ implode(', ', $c['environments']) }}</td><td class="small">{{ implode(', ', $c['currencies'] ?? []) }}</td><td class="small">{{ implode(', ', $c['channels'] ?? []) }}</td></tr>
    @endforeach
  </tbody></table></div>
</section>
@endsection
