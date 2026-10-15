@extends('layouts.admin')
@section('title', 'Merchants')
@section('content')
<section class="card">
  <form method="get" class="inline-form">
    <div><label for="q">Search</label><input id="q" name="q" type="text" value="{{ request('q') }}" placeholder="Name, Merchant ID, CRM deal ID"></div>
    <div><label for="state">State</label><select id="state" name="state"><option value="">All</option>@foreach ($states as $s)<option value="{{ $s }}" @selected(request('state') === $s)>{{ str_replace('_', ' ', $s) }}</option>@endforeach</select></div>
    <div><button class="btn secondary" type="submit">Filter</button></div>
  </form>
</section>
<section class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>Merchant</th><th>Merchant ID</th><th>CRM deal ID</th><th>Original agent</th><th>Stage</th><th>Route</th><th>Risk</th><th>Created</th></tr></thead>
    <tbody>
    @forelse ($merchants as $m)
      <tr>
        <td><a href="{{ route('admin.merchants.show', $m->id) }}">{{ $m->displayName() }}</a><div class="muted small">{{ $m->legal_name }}</div></td>
        <td class="mono small">{{ $m->public_id }}</td>
        <td class="mono small">{{ $m->crm_deal_id ?? '-' }}</td>
        <td>{{ $m->agent['name'] ?? '-' }} <span class="badge grey">Locked</span></td>
        <td>@include('partials.state', ['state' => $m->state])</td>
        <td class="small">{{ $m->processor_route ?? '-' }}</td>
        <td>{{ $m->risk_tier ? ucfirst($m->risk_tier) : '-' }}</td>
        <td class="small">{{ $m->created_at->format('M j, Y') }}</td>
      </tr>
    @empty
      <tr><td colspan="8" class="muted">No merchants yet. <a href="{{ route('admin.merchants.create') }}">Add one</a> or connect the CRM handoff.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  {{ $merchants->links() }}
</section>
@endsection
