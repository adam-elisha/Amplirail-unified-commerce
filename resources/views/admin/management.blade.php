@extends('layouts.admin')
@section('title', 'Management')
@section('crumb', 'Platform')
@section('content')
<div class="grid g2">
  <section class="card">
    <h2>Users and roles</h2>
    <div class="table-wrap"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Authenticator</th><th>Last sign-in</th></tr></thead><tbody>
      @foreach ($members as $mbr)
        <tr><td>{{ $mbr->user->name }}</td><td class="small">{{ $mbr->user->email }}</td><td>{{ str_replace('_', ' ', $mbr->role) }}</td><td>@include('partials.state', ['state' => $mbr->user->status])</td>
          <td>{{ $mbr->user->totp_confirmed_at ? 'Enrolled' : 'Not yet' }}</td><td class="small">{{ $mbr->user->last_login_at?->diffForHumans() ?? '-' }}</td></tr>
      @endforeach
    </tbody></table></div>
  </section>
  @if ($canInvite)
  <section class="card">
    <h2>Invite a staff member</h2>
    <form method="post" action="{{ route('admin.management.invite') }}">@csrf
      <label>Name</label><input name="name" type="text" required>
      <label>Work email</label><input name="email" type="email" required>
      <label>Role</label><select name="role">@foreach ($roles as $r)@continue($r === 'platform_owner')<option value="{{ $r }}">{{ str_replace('_', ' ', $r) }}</option>@endforeach</select>
      <button class="btn full" type="submit">Send invitation</button>
    </form>
  </section>
  @endif
</div>
<section class="card">
  <h2>Sign-in log</h2>
  <div class="table-wrap"><table><thead><tr><th>When</th><th>User</th><th>Portal</th><th>Event</th><th>Result</th><th>Method</th><th>IP</th><th>New device</th></tr></thead><tbody>
    @foreach ($signins as $e)
      <tr><td class="small">{{ $e->created_at }}</td><td class="small">{{ $e->email_masked }}</td><td>{{ $e->portal }}</td><td>{{ $e->event }}</td><td>{{ $e->result }}</td><td>{{ $e->method }}</td><td class="mono small">{{ $e->ip }}</td><td>{{ $e->new_device ? 'Yes' : '' }}</td></tr>
    @endforeach
  </tbody></table></div>
</section>
<section class="card">
  <h2>Audit trail</h2><div class="muted small">Immutable. Showing the latest 100 entries.</div>
  <div class="table-wrap"><table><thead><tr><th>When</th><th>Action</th><th>Actor</th><th>Subject</th><th>Correlation</th></tr></thead><tbody>
    @foreach ($audit as $a)
      <tr><td class="small">{{ $a->created_at }}</td><td>{{ $a->action }}</td><td class="mono small">{{ $a->actor_type }} {{ $a->actor_id }}</td><td class="mono small">{{ $a->subject_type }} {{ $a->subject_id }}</td><td class="mono small">{{ $a->correlation_id }}</td></tr>
    @endforeach
  </tbody></table></div>
</section>
@endsection
