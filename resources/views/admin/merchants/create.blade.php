@extends('layouts.admin')
@section('title', 'Add merchant')
@section('crumb', 'Operate · Merchants')
@section('content')
<form method="post" action="{{ route('admin.merchants.store') }}">
  @csrf
  <input type="hidden" name="form_key" value="{{ old('form_key', $formKey) }}">
  <div class="alert info small">Same canonical package as the CRM handoff. Sandbox MID, test API keys and webhook secret are provisioned immediately. Bank details are a token reference only: never type an account or card number here.</div>
  <div class="grid g2">
    <section class="card">
      <h2>Business</h2>
      <div class="inline-form">
        <div><label for="merchant_type">Merchant type</label><select id="merchant_type" name="merchant_type"><option value="standard">Standard</option><option value="mobile">Mobile</option><option value="test">Test</option></select></div>
        <div><label for="crm_deal_id">CRM deal ID (optional)</label><input id="crm_deal_id" name="crm_deal_id" type="text" value="{{ old('crm_deal_id') }}"></div>
      </div>
      <label for="legal_name">Legal name</label><input id="legal_name" name="legal_name" type="text" required value="{{ old('legal_name') }}">
      <label for="dba">DBA</label><input id="dba" name="dba" type="text" value="{{ old('dba') }}">
      <div class="inline-form">
        <div><label for="ein">EIN (encrypted)</label><input id="ein" name="ein" type="text" required placeholder="12-3456789" autocomplete="off"></div>
        <div><label for="entity_type">Entity type</label><select id="entity_type" name="entity_type">@foreach (['llc','corporation','s_corporation','partnership','sole_proprietorship','non_profit','other'] as $e)<option value="{{ $e }}" @selected(old('entity_type') === $e)>{{ str_replace('_', ' ', $e) }}</option>@endforeach</select></div>
      </div>
      <label for="website">Website</label><input id="website" name="website" type="url" value="{{ old('website') }}">
      <label for="phone">Phone</label><input id="phone" name="phone" type="text" value="{{ old('phone') }}">
      <label for="line1">Address</label><input id="line1" name="line1" type="text" required value="{{ old('line1') }}">
      <div class="inline-form">
        <div><label for="city">City</label><input id="city" name="city" type="text" required value="{{ old('city') }}"></div>
        <div><label for="region">State</label><input id="region" name="region" type="text" required maxlength="10" value="{{ old('region') }}"></div>
        <div><label for="postal_code">Postal code</label><input id="postal_code" name="postal_code" type="text" required value="{{ old('postal_code') }}"></div>
        <div><label for="country">Country</label><input id="country" name="country" type="text" required maxlength="2" value="{{ old('country', 'US') }}"></div>
      </div>
    </section>
    <section class="card">
      <h2>Owner, contact and banking</h2>
      <div class="inline-form">
        <div><label for="owner_name">Owner name</label><input id="owner_name" name="owner_name" type="text" required value="{{ old('owner_name') }}"></div>
        <div><label for="ownership_percent">Ownership %</label><input id="ownership_percent" name="ownership_percent" type="number" min="0" max="100" value="{{ old('ownership_percent', 100) }}"></div>
      </div>
      <label for="owner_identity_ref">Owner identity reference (KYC system)</label><input id="owner_identity_ref" name="owner_identity_ref" type="text" required value="{{ old('owner_identity_ref') }}">
      <div class="inline-form">
        <div><label for="contact_name">Primary user name</label><input id="contact_name" name="contact_name" type="text" required value="{{ old('contact_name') }}"></div>
        <div><label for="contact_email">Primary user email</label><input id="contact_email" name="contact_email" type="email" required value="{{ old('contact_email') }}"></div>
      </div>
      <div class="inline-form">
        <div><label for="bank_token_ref">Settlement bank token reference</label><input id="bank_token_ref" name="bank_token_ref" type="text" required value="{{ old('bank_token_ref') }}"></div>
        <div><label for="bank_last4">Bank last 4</label><input id="bank_last4" name="bank_last4" type="text" maxlength="4" value="{{ old('bank_last4') }}"></div>
      </div>
      <div class="inline-form">
        <div><label for="agent_id">Agent ID (locked after create)</label><input id="agent_id" name="agent_id" type="text" value="{{ old('agent_id', 'house') }}"></div>
        <div><label for="agent_name">Agent name</label><input id="agent_name" name="agent_name" type="text" value="{{ old('agent_name', 'House account') }}"></div>
      </div>
    </section>
    <section class="card span2">
      <h2>Processing profile</h2>
      <div class="inline-form">
        <div><label for="mcc">MCC</label><input id="mcc" name="mcc" type="text" maxlength="4" required value="{{ old('mcc') }}"></div>
        <div><label for="currency">Settlement currency</label><input id="currency" name="currency" type="text" maxlength="3" value="{{ old('currency', 'USD') }}"></div>
        <div><label for="monthly_volume">Monthly volume</label><input id="monthly_volume" name="monthly_volume" type="text" required value="{{ old('monthly_volume') }}" placeholder="40000.00"></div>
        <div><label for="avg_ticket">Average ticket</label><input id="avg_ticket" name="avg_ticket" type="text" required value="{{ old('avg_ticket') }}" placeholder="45.00"></div>
        <div><label for="max_ticket">Highest ticket</label><input id="max_ticket" name="max_ticket" type="text" required value="{{ old('max_ticket') }}" placeholder="500.00"></div>
      </div>
      <label for="products">Products and services</label><input id="products" name="products" type="text" required value="{{ old('products') }}">
      <fieldset class="actions"><legend class="muted small">Channels</legend>
        @foreach (\Modules\Onboarding\Application\HandoffPayload::CHANNELS as $c)
          <label><input type="checkbox" name="channels[]" value="{{ $c }}" @checked(in_array($c, old('channels', ['ecommerce']), true))> {{ str_replace('_', ' ', $c) }}</label>
        @endforeach
      </fieldset>
      <button class="btn" type="submit">Create merchant and provision sandbox</button>
    </section>
  </div>
</form>
@endsection
