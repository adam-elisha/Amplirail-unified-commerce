@extends('layouts.admin')
@section('title', 'BIN routing')
@section('crumb', 'Payments · Routing')
@section('content')
<section class="card">
  <p class="muted small">Send certain cards to certain processors first, for example US debit cards to the lowest-cost processor or commercial cards to a high-ticket one. A preference only changes the ORDER of the merchant's own eligible MIDs; it never adds a MID and never sends a declined payment elsewhere. When several match, the lowest priority number wins, then the most specific. Card facts come from the BIN table ({{ number_format($binCount) }} ranges loaded) and from the card details returned with each token.</p>
  <div class="table-wrap"><table>
    <thead><tr><th>Preference</th><th>Cards</th><th>Merchant</th><th>Processors first</th><th class="num">Priority</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse ($prefs as $p)
      <tr>
        <td>{{ $p->name }}</td>
        <td class="small">{{ collect([$p->bin_prefix ? 'BIN '.$p->bin_prefix : null, $p->card_brand, $p->card_type, $p->issuer_country ? 'issued in '.$p->issuer_country : null, $p->commercial === null ? null : ($p->commercial ? 'commercial' : 'consumer')])->filter()->implode(' · ') ?: 'all cards' }}</td>
        <td>{{ $p->merchant_id ? ($merchants[$p->merchant_id] ?? $p->merchant_id) : 'All merchants' }}</td>
        <td class="small mono">{{ implode(' → ', (array) $p->preferred_connectors) }}</td>
        <td class="num">{{ $p->priority }}</td>
        <td>@include('partials.state', ['state' => $p->status === 'active' ? 'active' : 'suspended'])</td>
        <td>@if ($canEdit)<form method="post" action="{{ route('admin.bins.toggle', $p->id) }}">@csrf<button class="btn secondary small" type="submit">{{ $p->status === 'active' ? 'Pause' : 'Resume' }}</button></form>@endif</td>
      </tr>
    @empty
      <tr><td colspan="7" class="muted">No preferences: each merchant's MIDs are tried in their priority order.</td></tr>
    @endforelse
    </tbody>
  </table></div>
</section>
@if ($canEdit)
<div class="grid g2">
  <section class="card">
    <h2>Add a preference</h2>
    <form method="post" action="{{ route('admin.bins.store') }}">@csrf
      <label for="name">Name</label><input id="name" name="name" type="text" required maxlength="120" placeholder="e.g. US debit to lowest cost">
      <div class="grid g3">
        <div><label for="bin_prefix">BIN starts with</label><input id="bin_prefix" name="bin_prefix" type="text" inputmode="numeric" maxlength="8"></div>
        <div><label for="card_brand">Brand</label><select id="card_brand" name="card_brand"><option value="">any</option><option>visa</option><option>mastercard</option><option>amex</option><option>discover</option></select></div>
        <div><label for="card_type">Type</label><select id="card_type" name="card_type"><option value="">any</option><option>credit</option><option>debit</option><option>prepaid</option></select></div>
        <div><label for="issuer_country">Issuer country</label><input id="issuer_country" name="issuer_country" type="text" maxlength="2" placeholder="US"></div>
        <div><label for="commercial">Card holder</label><select id="commercial" name="commercial"><option value="">any</option><option value="yes">commercial</option><option value="no">consumer</option></select></div>
        <div><label for="priority">Priority</label><input id="priority" name="priority" type="number" min="1" max="1000" value="100"></div>
      </div>
      <label for="merchant_id">Merchant</label><select id="merchant_id" name="merchant_id"><option value="">All merchants</option>@foreach ($merchants as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
      <label>Processors to try first (in order)</label>
      <div class="grid g3">@for ($i = 0; $i < 3; $i++)<div><select name="connectors[]" aria-label="Processor {{ $i + 1 }}"><option value="">-</option>@foreach ($connectors as $c)<option>{{ $c }}</option>@endforeach</select></div>@endfor</div>
      <button class="btn" type="submit">Add preference</button>
    </form>
  </section>
  <section class="card">
    <h2>Load the BIN table</h2>
    <p class="muted small">CSV with a header row: <span class="mono">bin_prefix, card_brand, card_type, card_tier, issuer_name, issuer_country, commercial</span>. Prefixes are 6 to 8 digits; longer values are rejected so no card number can ever be stored. Existing prefixes are updated.</p>
    <form method="post" action="{{ route('admin.bins.import') }}" enctype="multipart/form-data">@csrf
      <label for="file">CSV file</label><input id="file" name="file" type="file" accept=".csv,text/csv" required>
      <button class="btn" type="submit">Import</button>
    </form>
  </section>
</div>
@endif
@endsection
