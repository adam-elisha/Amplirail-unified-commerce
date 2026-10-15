# AmpliRail private label (round 3) — install guide

Built 2026-10-14 for the dev install at `~/amplirail/amplirail-unified-commerce` (getpayments101.amplipay.online).
Copy the files from this zip into the project at the same paths, make the 7 small edits below, then run the
migration. Every edit is a plain find-and-replace; back up each file first.

## New files (copy as-is)

| File | What it is |
| --- | --- |
| database/migrations/2026_10_14_000300_create_brands_table.php | `brands` table (one brand per organization) |
| app/Support/Branding/Brand.php | Brand value object (platform default = AmpliRail) |
| app/Support/Branding/BrandRecord.php | Eloquent model for `brands` |
| app/Support/Branding/BrandResolver.php | Finds the brand by host name (cached 5 minutes) |
| app/Http/Middleware/ApplyBrand.php | Shares `$brand` with every view |
| app/Http/Controllers/Admin/PartnerController.php | Platform owner screen: create partners, edit brands |
| resources/views/admin/partners/index.blade.php, form.blade.php | Partners screen |
| resources/views/partials/brand-style.blade.php, brand-mark.blade.php | Brand colours and logo tile |

## Edits

1. **bootstrap/app.php** — register the middleware for web pages.
   Find: `$middleware->append(SecurityHeaders::class);`
   Replace with:
   ```php
   $middleware->append(SecurityHeaders::class);
   $middleware->appendToGroup('web', \App\Http\Middleware\ApplyBrand::class);
   ```

2. **resources/views/layouts/base.blade.php** — brand name in the tab title and brand colours.
   Find: `<title>@yield('title', 'AmpliRail') · AmpliRail</title>`
   Replace with: `<title>@yield('title', $brand->name ?? 'AmpliRail') · {{ $brand->name ?? 'AmpliRail' }}</title>`
   Find: `  @stack('head')`
   Replace with:
   ```blade
     @include('partials.brand-style')
     @stack('head')
   ```

3. **resources/views/layouts/auth.blade.php** — sign-in page brand.
   Find: `<div class="brand"><span class="brand-mark">A</span><strong>AmpliRail</strong></div>`
   Replace with: `<div class="brand">@include('partials.brand-mark')<strong>{{ $brand->name ?? 'AmpliRail' }}</strong></div>`
   Find: `<h1>One rail for every processor, channel and partner.</h1>`
   Replace with: `<h1>{{ $brand->tagline ?? 'One rail for every processor, channel and partner.' }}</h1>`
   Find the three `config('amplirail.legal.terms')`, `config('amplirail.legal.privacy')`, `config('amplirail.legal.security')`
   and replace with `$brand->termsUrl`, `$brand->privacyUrl`, `$brand->securityUrl`.
   Find: `© {{ date('Y') }} AmpliPay Group Inc.` Replace with: `© {{ date('Y') }} {{ $brand->company ?? 'AmpliPay Group Inc.' }}`

4. **resources/views/partials/footer.blade.php** — same three link replacements as step 3, and
   `<span>© {{ date('Y') }} AmpliPay Group Inc.</span>` → `<span>© {{ date('Y') }} {{ $brand->company ?? 'AmpliPay Group Inc.' }}</span>`

5. **resources/views/layouts/admin.blade.php** and **layouts/merchant.blade.php** — sidebar brand.
   Find: `<span class="brand-mark">A</span><span><strong>AmpliRail</strong>`
   Replace with: `@include('partials.brand-mark')<span><strong>{{ $brand->name ?? 'AmpliRail' }}</strong>`
   In admin.blade.php only, in the `'Platform' => [` group add:
   `['admin.partners.index', 'Private label partners', 'platform.emergency_override'],`

6. **routes/web.php** — add `use App\Http\Controllers\Admin\PartnerController;` with the other admin imports, and inside the
   admin group (next to the reconciliation routes):
   ```php
   Route::get('partners', [PartnerController::class, 'index'])->middleware('permission:platform.emergency_override')->name('partners.index');
   Route::post('partners', [PartnerController::class, 'store'])->middleware('permission:platform.emergency_override')->name('partners.store');
   Route::post('partners/{org}', [PartnerController::class, 'update'])->middleware('permission:platform.emergency_override')->name('partners.update');
   ```

7. **app/Http/Controllers/Auth/SignInController.php** — a partner's web address only lets that partner's users in.
   In `login()`, find:
   ```php
           $request->session()->regenerate();
           $request->session()->put(['auth.pending' => $user->id, 'auth.portal' => $portal]);
   ```
   and put this directly above it:
   ```php
           $brand = app(\App\Support\Branding\BrandResolver::class)->current();
           if ($brand->tenantId !== null && $user->primaryMembership()?->tenant_id !== $brand->tenantId) {
               return back()->withInput(['email' => $data['email']])->with('error', 'Email or password is incorrect.');
           }
   ```

## Then

```bash
php artisan migrate --database=pgsql_owner --force
php artisan optimize:clear
```

## How it works

- Platform owners see **Platform → Private label partners**. "Add a private label partner" creates the partner's own
  organization (tenant), its brand and an emailed invitation for the partner's owner.
- Each partner's data is isolated by the existing row-level security, exactly like eData's own organization.
- The brand is chosen by the web address. Add each partner address in DNS and in CWP (with SSL) pointing to the same
  `public` folder. Unknown addresses show the AmpliRail brand.
- Logos are stored inside the database as data URIs (PNG, JPEG or SVG up to 200 KB), so they work with the existing
  Content Security Policy (`img-src 'self' data:`).

## Known follow-ups

- Invitation and code emails are queued (`QUEUE_CONNECTION=database`): a queue worker must run
  (`php artisan queue:work`, for example under Supervisor or a CWP cron every minute with `--stop-when-empty`).
- Per-brand email sender name and address.
- A new partner starts empty: it needs its fee schedules and routing rules (run the seeder for that tenant or add them on screen).
