@extends('layouts.admin')
@section('title', 'Merchant 360')
@section('crumb', 'Operate · Merchants')
@section('content')
@php
  $can = fn (string $p) => in_array($p, $permissions ?? [], true);
  $order = array_keys($progress);
  $pos = array_search($m->state, $order, true);
@endphp

<div class="card-head">
  <div class="actions">
    <span class="avatar">{{ strtoupper(substr($m->displayName(), 0, 2)) }}</span>
    <div>
      <h1>{{ $m->legal_name }} @include('partials.state', ['state' => $m->state])</h1>
      <div class="muted small"><span class="mono">{{ $m->public_id }}</span> · <span class="mono">{{ $m->id }}</span> · DBA {{ $m->dba ?? '-' }} · MCC {{ $m->mcc }} · {{ $m->address['city'] ?? '' }}, {{ $m->address['region'] ?? '' }}</div>
    </div>
  </div>
  <div class="actions">
    <a class="btn secondary" href="{{ route('admin.transactions.index', ['merchant_id' => $m->id]) }}">View payments</a>
    @if ($m->state === 'validation' && $can('merchants.go_live.request') && ! $pendingGoLive)
      <form method="post" action="{{ route('admin.merchants.go-live', $m->id) }}">@csrf<button class="btn" type="submit">Request go-live</button></form>
    @endif
  </div>
</div>

<div class="grid g3">
  <div class="span2 grid">
    <section class="card">
      <h2>Onboarding</h2>
      <div class="muted small">Current state: {{ str_replace('_', ' ', $m->state) }}{{ $m->previous_state ? ' (resumes to '.str_replace('_', ' ', $m->previous_state).')' : '' }} · in this state since {{ $m->state_changed_at->diffForHumans() }}</div>
      <div class="progress">
        @foreach ($progress as $state => $label)
          @php $i = array_search($state, $order, true); @endphp
          <div class="{{ $pos !== false && $i < $pos ? 'done' : ($state === $m->state ? 'current' : '') }} {{ $m->state === 'active' ? 'done' : '' }}">{{ $label }}</div>
        @endforeach
      </div>
    </section>

    {{-- Day 0-1: documents (AR-169) --}}
    <section class="card">
      <div class="card-head"><div><h2>Document checklist</h2><div class="muted small">{{ $checklistSummary['complete'] ? 'Complete for the current stage.' : count($checklistSummary['outstanding']).' outstanding' }}@unless ($checklistSummary['enabled']) · checklist engine is off (manual confirmation)@endunless</div></div></div>
      <div class="table-wrap"><table>
        <thead><tr><th>Requirement</th><th>Stage</th><th>Source</th><th>Status</th></tr></thead>
        <tbody>
          @foreach ($checklist as $item)
            <tr><td>{{ $item['label'] }} <span class="muted small mono">{{ $item['document_type'] }}</span></td><td>{{ $item['stage'] }}</td><td>{{ $item['source'] }}</td>
              <td>@if ($item['satisfied'])<span class="badge green">Received</span>@else<span class="badge amber">Missing</span>@endif</td></tr>
          @endforeach
        </tbody>
      </table></div>
      @if ($documents->isNotEmpty())
        <h3>Files</h3>
        <div class="rows">
          @foreach ($documents as $d)
            <div><span>{{ $d->document_type }} · {{ $d->original_name }} · {{ number_format($d->size_bytes / 1024, 0) }} KB @include('partials.state', ['state' => $d->status])</span>
              <span class="actions">
                @if ($d->status === 'active' && $can('documents.view'))<a class="btn secondary small" href="{{ app(\Modules\Onboarding\Application\DocumentChecklist::class)->downloadUrl($d, 'admin') }}">Download (audited)</a>@endif
                @if ($d->status === 'active' && $can('documents.manage'))<form method="post" action="{{ route('admin.merchants.documents.remove', [$m->id, $d->id]) }}" data-confirm="Remove this document?">@csrf<button class="btn secondary small" type="submit">Remove</button></form>@endif
              </span></div>
          @endforeach
        </div>
      @endif
      @if ($can('documents.manage'))
        <details><summary>Upload a document</summary>
          <form method="post" action="{{ route('admin.merchants.documents.upload', $m->id) }}" enctype="multipart/form-data" class="inline-form">
            @csrf
            <div><label for="document_type">Type</label><select id="document_type" name="document_type">@foreach (collect($checklist)->pluck('document_type')->unique() as $t)<option>{{ $t }}</option>@endforeach<option value="other_evidence">other_evidence</option></select></div>
            <div><label for="file">PDF, JPEG or PNG, up to 10 MB</label><input id="file" name="file" type="file" accept=".pdf,.jpg,.jpeg,.png" required></div>
            <div><button class="btn" type="submit">Upload</button></div>
          </form>
        </details>
        <details><summary>Request more information (reopens the checklist)</summary>
          <form method="post" action="{{ route('admin.merchants.request-info', $m->id) }}" class="inline-form">
            @csrf
            <div><label>Document type</label><input name="document_type" type="text" required placeholder="ownership_proof"></div>
            <div><label>Label shown to merchant</label><input name="label" type="text" required></div>
            <div><label>Source</label><select name="source"><option value="processor">Processor</option><option value="risk">Risk</option><option value="operations">Operations</option></select></div>
            <div><label>Reason</label><input name="reason" type="text" required></div>
            <div><button class="btn secondary" type="submit">Request</button></div>
          </form>
        </details>
        @if (in_array($m->state, ['submitted', 'needs_info'], true))
          <details><summary>Confirm documents manually</summary>
            <form method="post" action="{{ route('admin.merchants.documents.complete', $m->id) }}" class="inline-form">@csrf
              <div><label>Reason (audited)</label><input name="reason" type="text" required minlength="5"></div><div><button class="btn secondary" type="submit">Mark complete</button></div></form>
          </details>
        @endif
      @endif
    </section>

    {{-- Day 1: KYB and underwriting --}}
    <section class="card">
      <h2>KYB and underwriting</h2>
      <div class="grid g2">
        <div>
          <h3>KYB / KYC</h3>
          @if ($kyb)<p>@include('partials.state', ['state' => $kyb->status === 'passed' ? 'passed' : 'manual_review']) {{ $kyb->provider }} <span class="mono small">{{ $kyb->reference }}</span></p>
            @foreach ($kyb->reasons as $r)<div class="small check-bad">{{ $r }}</div>@endforeach
          @else<p class="muted">Runs automatically when documents are complete.</p>@endif
          @if ($m->state === 'kyb_pending' && $can('merchants.edit'))<form method="post" action="{{ route('admin.merchants.kyb.run', $m->id) }}">@csrf<button class="btn secondary small" type="submit">Run KYB now</button></form>@endif
          @if ($m->state === 'manual_review' && $can('risk.decide'))
            <form method="post" action="{{ route('admin.merchants.kyb.resolve', $m->id) }}" class="inline-form">@csrf<div><label>Resolution reason</label><input name="reason" type="text" required></div><div><button class="btn secondary small" type="submit">Resolve KYB</button></div></form>
          @endif
        </div>
        <div>
          <h3>RoboRiskAnalysis</h3>
          @if ($risk)
            <dl class="kv"><dt>Score</dt><dd>{{ $risk->score }} / 100 · <span class="badge {{ $risk->band === 'green' ? 'green' : ($risk->band === 'yellow' ? 'amber' : 'red') }}">{{ ucfirst($risk->band) }}</span></dd>
              <dt>Recommendation</dt><dd>{{ $risk->recommendation }} ({{ $risk->confidence }}% confidence)</dd>
              <dt>Pre-submission gate</dt><dd>{{ $risk->gate_complete ? 'Complete' : 'Open' }}</dd>
              <dt>Recommended controls</dt><dd class="small">@foreach ($risk->recommended_controls as $k => $v){{ $k }}={{ is_bool($v) ? ($v ? 'yes' : 'no') : $v }} @endforeach</dd>
              <dt>Decision</dt><dd>{{ $risk->decision ? $risk->decision.' by '.$risk->decided_by : 'Pending human decision' }}</dd></dl>
            @foreach ($risk->signals as $s)<div class="small check-bad">{{ $s['description'] }} <span class="muted">({{ $s['evidence'] }})</span></div>@endforeach
          @else
            <p class="muted">Assessment requested at handoff.</p>
            @if ($can('risk.view'))<form method="post" action="{{ route('admin.merchants.risk.assess', $m->id) }}">@csrf<button class="btn secondary small" type="submit">Request assessment</button></form>@endif
          @endif
        </div>
      </div>
      @if (in_array($m->state, ['underwriting', 'manual_review'], true) && $can('risk.decide'))
        <details open><summary>Record the underwriting decision (step-up required)</summary>
          <form method="post" action="{{ route('admin.merchants.risk.decide', $m->id) }}" class="inline-form">
            @csrf
            <div><label>Decision</label><select name="decision"><option value="approve">Approve</option><option value="manual_review">Manual review</option><option value="decline">Decline</option></select></div>
            <div><label>Reason</label><input name="reason" type="text" required minlength="5"></div>
            <div><label>Authenticator code</label><input name="step_up_code" inputmode="numeric" maxlength="6" required autocomplete="one-time-code"></div>
            <div><label><input type="checkbox" name="apply_controls" value="1" checked> Apply recommended controls</label></div>
            <div><button class="btn" type="submit">Record decision</button></div>
          </form>
        </details>
      @endif
    </section>

    {{-- Day 1-3: processor (AR-170) --}}
    <section class="card">
      <div class="card-head"><div><h2>Processor eligibility and boarding</h2>
        <div class="muted small">@if ($eligibility) Rule set v{{ $eligibility['rule_set_version'] }} · evaluated {{ \Illuminate\Support\Carbon::parse($eligibility['evaluated_at'])->diffForHumans() }}@else Not evaluated yet @endif</div></div>
        @if ($can('routing.view'))<form method="post" action="{{ route('admin.merchants.eligibility', $m->id) }}">@csrf<button class="btn secondary small" type="submit">Evaluate eligibility</button></form>@endif
      </div>
      @if ($eligibility)
        <div class="grid g2">
          <div><h3>Eligible routes</h3>
            @forelse ($eligibility['eligible'] as $r)<div class="check-ok">{{ $r['name'] }} <span class="mono small">{{ $r['code'] }}</span> · priority {{ $r['priority'] }}</div>@empty<p class="muted">None.</p>@endforelse</div>
          <div><h3>Not eligible</h3>
            @foreach ($eligibility['ineligible'] as $r)
              <div class="check-bad">{{ $r['name'] }}</div>
              @foreach ($r['failed_rules'] as $f)<div class="small muted">· {{ $f['rule'] }}: {{ $f['message'] }} (needs {{ is_array($f['expected']) ? implode(', ', array_slice($f['expected'], 0, 6)) : json_encode($f['expected']) }}; has {{ is_array($f['actual']) ? implode(', ', $f['actual']) : json_encode($f['actual']) }})</div>@endforeach
            @endforeach</div>
        </div>
      @endif
      <dl class="kv"><dt>Route</dt><dd>{{ $m->processor_route ?? '-' }}</dd><dt>Processor reference</dt><dd class="mono">{{ $m->processor_reference ?? '-' }}</dd>
        <dt>Processor approved</dt><dd>{{ $m->processor_approved_at?->format('M j, Y H:i') ?? '-' }}</dd><dt>Production MID</dt><dd>{{ $m->mid_created_at ? 'Recorded' : '-' }}</dd>
        <dt>Credentials</dt><dd>{{ $m->credentials_created_at ? 'Stored via secrets manager' : '-' }}</dd></dl>
      @if ($can('merchants.edit'))
        @if ($m->state === 'approved')
          <form method="post" action="{{ route('admin.merchants.submit', $m->id) }}" class="inline-form">@csrf
            <div><label>Route</label><select name="route">@foreach ($eligibility['eligible'] ?? [] as $r)<option value="{{ $r['code'] }}">{{ $r['name'] }}</option>@endforeach<option value="nmi_bridge:us">nmi_bridge:us (manual)</option></select></div>
            <div><label>RRA gate override reason (only if gate open)</label><input name="override_reason" type="text"></div>
            <div><button class="btn" type="submit">Submit to processor</button></div></form>
        @endif
        @if ($m->state === 'boarding' && ! $m->processor_approved_at)
          <form method="post" action="{{ route('admin.merchants.processor-approved', $m->id) }}" class="inline-form">@csrf<div><label>Approval note (portal reference, email ID)</label><input name="note" type="text" required></div><div><button class="btn secondary" type="submit">Record processor approval</button></div></form>
        @endif
        @if ($m->state === 'boarding' && $m->processor_approved_at && ! $m->mid_created_at)
          <form method="post" action="{{ route('admin.merchants.mid', $m->id) }}" class="inline-form">@csrf
            <div><label>Connector</label><select name="connector">@foreach ($connectors as $c)<option>{{ $c }}</option>@endforeach</select></div>
            <div><label>Provider MID</label><input name="provider_mid" type="text" required></div><div><label>TID</label><input name="provider_tid" type="text"></div>
            <div><label>Descriptor</label><input name="descriptor" type="text" maxlength="25" value="{{ $m->descriptor }}"></div>
            <div><button class="btn secondary" type="submit">Record production MID</button></div></form>
        @endif
        @if ($m->state === 'boarding' && $m->mid_created_at)
          <form method="post" action="{{ route('admin.merchants.credentials', $m->id) }}" class="inline-form">@csrf
            <div><label>Live MID</label><select name="merchant_account_id">@foreach (collect($accounts)->where('environment', 'live') as $a)<option value="{{ $a->id }}">{{ $a->label }} ({{ $a->connector }})</option>@endforeach</select></div>
            <div><label>Secrets Manager reference (name, not the key)</label><input name="credential_ref" type="text" required placeholder="mid/{{ $m->public_id }}/nmi"></div>
            <div><button class="btn secondary" type="submit">Record credentials</button></div></form>
        @endif
      @endif
    </section>

    {{-- Day 3-4: configuration, validation and go-live (AR-172) --}}
    <section class="card">
      <h2>Go-live configuration</h2>
      @if ($can('merchants.risk.edit') && ! in_array($m->state, ['active', 'closed'], true))
        <form method="post" action="{{ route('admin.merchants.configure', $m->id) }}" class="inline-form">@csrf
          <div><label>Descriptor</label><input name="descriptor" type="text" maxlength="25" value="{{ $m->descriptor }}"></div>
          <div><label>Max ticket (minor units)</label><input name="max_ticket_minor" type="number" value="{{ $m->risk_profile['max_ticket_minor'] ?? '' }}"></div>
          <div><label>Reserve (bps)</label><input name="reserve_bps" type="number" value="{{ $m->risk_profile['reserve_bps'] ?? '' }}"></div>
          <div><label>Funding delay (days)</label><input name="funding_delay_days" type="number" value="{{ $m->risk_profile['funding_delay_days'] ?? '' }}"></div>
          <div><label>PCI status</label><select name="pci_status">@foreach (['pending','compliant','validated','not_compliant'] as $p)<option @selected($m->pci_status === $p)>{{ $p }}</option>@endforeach</select></div>
          <div><label>PCI expires</label><input name="pci_expires_at" type="date" value="{{ $m->pci_expires_at?->toDateString() }}"></div>
          <div><label>Fee schedule</label><select name="fee_schedule_id"><option value="">-</option>@foreach ($feeSchedules as $f)<option value="{{ $f->id }}" @selected($m->fee_schedule_id === $f->id)>{{ $f->version }}</option>@endforeach</select></div>
          <div><label>Payout method</label><select name="payout_method">@foreach (['ach','wire_intl','usdt'] as $p)<option @selected($m->payout_method === $p)>{{ $p }}</option>@endforeach</select></div>
          <div><label>Funding schedule</label><select name="funding_schedule">@foreach (['daily','weekly','monthly'] as $p)<option @selected($m->funding_schedule === $p)>{{ $p }}</option>@endforeach</select></div>
          <div><label><input type="checkbox" name="settlement_verified" value="1" @checked($m->settlement_verified_at)> Settlement account verified</label></div>
          <div><button class="btn secondary" type="submit">Save configuration</button></div>
        </form>
      @else
        <p class="muted small">Locked. Changes after go-live go through Approvals.</p>
      @endif
      @if ($can('fees.manage'))
        <details><summary>Signed fee agreement {{ $agreement ? '(signed '.$agreement->signed_at->format('M j, Y').' by '.$agreement->signer_name.')' : '(none)' }}</summary>
          <form method="post" action="{{ route('admin.merchants.agreement', $m->id) }}" class="inline-form">@csrf
            <div><label>Fee schedule</label><select name="fee_schedule_id">@foreach ($feeSchedules as $f)<option value="{{ $f->id }}">{{ $f->version }}</option>@endforeach</select></div>
            <div><label>Signer</label><input name="signer_name" type="text" required></div>
            <div><label>Signed document SHA-256</label><input name="document_hash" type="text" required pattern="[a-f0-9]{64}"></div>
            <div><button class="btn secondary" type="submit">Record signed agreement</button></div></form>
        </details>
      @endif
    </section>

    <section class="card">
      <div class="card-head"><div><h2>Validation and go-live gate</h2>
        <div class="muted small">Every check is evaluated live; anything that cannot be evaluated fails. A second authorized person approves.</div></div>
        @if ($m->state === 'test_mode' && $can('merchants.edit'))<form method="post" action="{{ route('admin.merchants.validate', $m->id) }}">@csrf<button class="btn secondary" type="submit">Run validation</button></form>@endif
      </div>
      @if ($validation)
        <p class="small">Last passed validation <span class="mono">{{ $validation->id }}</span> · {{ $validation->created_at->diffForHumans() }}</p>
      @endif
      <div class="grid g2">
        @foreach ($goLiveChecks as $code => $label)
          @php $c = collect($goLive?->checks ?? [])->firstWhere('code', $code); @endphp
          <div class="{{ $c === null ? '' : ($c['passed'] ? 'check-ok' : 'check-bad') }}">{{ $label }}@if ($c && ! $c['evaluated']) <span class="badge red">could not evaluate</span>@endif</div>
        @endforeach
      </div>
      <p class="muted small">{{ $goLive ? 'Last evaluated '.$goLive->created_at->diffForHumans().' by '.$goLive->actor_id : 'Not evaluated yet.' }}</p>
      @if ($pendingGoLive)<div class="alert info">Go-live request <span class="mono">{{ $pendingGoLive->id }}</span> is waiting for a second approver in <a href="{{ route('admin.approvals') }}">Approvals</a>.</div>@endif
      @if ($m->state === 'validation' && $can('merchants.go_live.approve') && ! $pendingGoLive)
        <details><summary>Emergency override (Platform Owner only)</summary>
          <p class="small muted">Requires the Platform Owner, a step-up code, a detailed reason and a second authorized approver. Recorded as an immutable audit event.</p>
          <form method="post" action="{{ route('admin.merchants.go-live-override', $m->id) }}" class="inline-form" data-confirm="Request an emergency go-live override?">@csrf
            <div><label>Reason (20+ characters)</label><input name="reason" type="text" required minlength="20"></div>
            <div><label>Authenticator code</label><input name="step_up_code" inputmode="numeric" maxlength="6" required></div>
            <div><button class="btn danger" type="submit">Request override</button></div></form>
        </details>
      @endif
    </section>
  </div>

  <div class="grid">
    <section class="card">
      <h2>CRM</h2>
      <dl class="kv"><dt>Deal ID</dt><dd class="mono">{{ $m->crm_deal_id ?? '-' }}</dd><dt>Source</dt><dd>{{ $m->source === 'crm' ? 'Partner CRM' : 'Manual entry' }}</dd>
        <dt>Original agent</dt><dd>{{ $m->agent['name'] ?? '-' }} <span class="badge grey">Locked</span></dd><dt>First received</dt><dd>{{ $m->created_at->format('M j, Y') }}</dd></dl>
    </section>
    <section class="card">
      <h2>MIDs and routes</h2>
      <div class="rows">
        @forelse ($accounts as $a)
          <div><span class="mono small">{{ $a->label }}</span><span>{{ $a->connector }} <span class="badge {{ $a->environment === 'live' ? 'live' : 'sandbox' }}">{{ $a->environment }}</span> @include('partials.state', ['state' => $a->status])</span></div>
        @empty<div class="muted">No MIDs.</div>@endforelse
      </div>
    </section>
    <section class="card">
      <h2>Payments, 30 days</h2>
      <dl class="kv"><dt>Sandbox</dt><dd>{{ $payments['count'] }} · {{ money($payments['amount_minor'], $payments['currency'] ?? $m->currency) }}</dd>
        <dt>Live</dt><dd>{{ $livePayments['count'] }} · {{ money($livePayments['amount_minor'], $livePayments['currency'] ?? $m->currency) }}</dd></dl>
    </section>
    @if ($can('merchants.edit') && ! in_array($m->state, ['closed'], true))
      <section class="card">
        <h2>Account status</h2>
        @if ($m->state === 'suspended')
          <form method="post" action="{{ route('admin.merchants.reinstate', $m->id) }}">@csrf<label>Reason</label><input name="reason" type="text" required><button class="btn secondary full" type="submit">Reinstate</button></form>
        @else
          <form method="post" action="{{ route('admin.merchants.suspend', $m->id) }}" data-confirm="Suspend this merchant?">@csrf<label>Reason</label><input name="reason" type="text" required><button class="btn danger full" type="submit">Suspend</button></form>
        @endif
      </section>
    @endif
    <section class="card">
      <h2>Event timeline</h2><div class="muted small">Append-only. Corrections are new events.</div>
      <div class="timeline">
        @foreach ($events as $e)
          <div><span class="mono small">{{ \Illuminate\Support\Carbon::parse($e['occurred_at'])->format('M j H:i:s') }}</span>
            <span>{{ $e['type'] }} <span class="muted small">· {{ $e['actor']['type'] }} {{ $e['actor']['id'] }}</span></span></div>
        @endforeach
      </div>
    </section>
  </div>
</div>
@endsection
