@extends('layouts.admin')
@section('title', 'Private label partners')
@section('crumb', 'Platform')
@section('content')
<section class="card">
  <p class="muted small">Each partner is its own organization: its own staff, merchants, MIDs, fee schedules and data, kept separate from every other organization by the database. Users who open a partner's web address see the partner's name, logo and colours on the sign-in page, the portals, the pay page and every footer. Only platform owners can see this page.</p>
  <div class="table-wrap"><table>
    <thead><tr><th>Organization</th><th>Brand</th><th>Type</th><th>Web addresses</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @foreach ($orgs as $o)
      @php($r = $brands[$o->id] ?? null)
      <tr>
        <td>{{ $o->name }}<div class="mono small muted">{{ $o->id }}</div>@if ($o->id === $me)<span class="badge grey">your organization</span>@endif</td>
        <td>
          @if ($r)
            <span class="badge">{{ $r->mark }}</span> {{ $r->name }} <span class="mono small muted">{{ $r->primary_color }}</span>
            <div class="muted small">{{ $r->company }}</div>
          @else
            <span class="muted">Default ({{ $platform->name }})</span>
          @endif
        </td>
        <td class="small">{{ $r ? ($types[$r->partner_type] ?? $r->partner_type) : '-' }}</td>
        <td class="small mono">{{ $r && $r->hosts ? implode(', ', $r->hosts) : '-' }}</td>
        <td>@include('partials.state', ['state' => $o->status])</td>
        <td><a class="btn secondary small" href="#edit-{{ $o->id }}">Edit brand</a></td>
      </tr>
    @endforeach
    </tbody>
  </table></div>
</section>

@foreach ($orgs as $o)
  @php($r = $brands[$o->id] ?? null)
  <section class="card" id="edit-{{ $o->id }}">
    <details>
      <summary><strong>Brand for {{ $o->name }}</strong> <span class="muted small">{{ $o->id }}</span></summary>
      <form method="post" action="{{ route('admin.partners.update', $o->id) }}" enctype="multipart/form-data">@csrf
        @include('admin.partners.form', ['record' => $r])
        <button class="btn" type="submit">Save brand</button>
      </form>
    </details>
  </section>
@endforeach

<section class="card" id="new">
  <h2>Add a private label partner</h2>
  <p class="muted small">Creates the partner's organization and brand, and emails an invitation to the partner's owner. After saving, point each web address to this server in DNS and add it in CWP with an SSL certificate.</p>
  <form method="post" action="{{ route('admin.partners.store') }}" enctype="multipart/form-data">@csrf
    @include('admin.partners.form', ['record' => null])
    <div class="grid g3">
      <div><label>Owner's name</label><input name="owner_name" type="text" required maxlength="120" value="{{ old('owner_name') }}"></div>
      <div><label>Owner's email</label><input name="owner_email" type="email" required maxlength="190" value="{{ old('owner_email') }}"></div>
      <div class="actions" style="align-self:end"><button class="btn" type="submit">Create partner</button></div>
    </div>
  </form>
</section>
@endsection
