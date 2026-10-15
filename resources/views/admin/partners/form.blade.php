@php($r = $record ?? null)
<div class="grid g3">
  <div><label>Product name shown to users</label><input name="name" type="text" required maxlength="80" value="{{ old('name', $r?->name) }}" placeholder="e.g. Acme Pay"></div>
  <div><label>Company (legal name for footers)</label><input name="company" type="text" required maxlength="120" value="{{ old('company', $r?->company) }}"></div>
  <div><label>Partner type</label><select name="partner_type">@foreach ($types as $k => $label)<option value="{{ $k }}" @selected(old('partner_type', $r?->partner_type) === $k)>{{ $label }}</option>@endforeach</select></div>
  <div><label>Logo letters (1 to 3)</label><input name="mark" type="text" required maxlength="3" value="{{ old('mark', $r?->mark) }}"></div>
  <div><label>Main colour</label><input name="primary_color" type="color" required value="{{ old('primary_color', $r?->primary_color ?? '#1A5CFF') }}"></div>
  <div><label>Dark colour (menu and headings)</label><input name="navy_color" type="color" required value="{{ old('navy_color', $r?->navy_color ?? '#0B1F4B') }}"></div>
  <div class="span2"><label>Tagline on the sign-in page</label><input name="tagline" type="text" maxlength="160" value="{{ old('tagline', $r?->tagline) }}"></div>
  <div><label>Logo (PNG, JPEG or SVG, max 200 KB)</label><input name="logo" type="file" accept=".png,.jpg,.jpeg,.svg,image/png,image/jpeg,image/svg+xml">@if ($r?->logo_data)<label class="small"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label>@endif</div>
  <div><label>Support email</label><input name="support_email" type="email" maxlength="120" value="{{ old('support_email', $r?->support_email) }}"></div>
  <div><label>Support phone</label><input name="support_phone" type="text" maxlength="40" value="{{ old('support_phone', $r?->support_phone) }}"></div>
  <div><label>Web addresses (one per line)</label><textarea name="hosts" rows="3" placeholder="pay.partner.com&#10;admin.partner.com">{{ old('hosts', implode("\n", $r?->hosts ?? [])) }}</textarea></div>
  <div><label>Terms of service link (optional)</label><input name="terms_url" type="url" maxlength="300" value="{{ old('terms_url', $r?->terms_url) }}"></div>
  <div><label>Privacy policy link (optional)</label><input name="privacy_url" type="url" maxlength="300" value="{{ old('privacy_url', $r?->privacy_url) }}"></div>
  <div><label>Security page link (optional)</label><input name="security_url" type="url" maxlength="300" value="{{ old('security_url', $r?->security_url) }}"></div>
</div>
