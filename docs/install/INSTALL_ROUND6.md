# AmpliRail round 6: MID management, BIN routing and platform fees (partner markup) — install guide

Built 2026-10-14. Install after rounds 1 to 5. Back up each file before editing.

## New files

| File | What it is |
| --- | --- |
| database/migrations/2026_10_14_000700_create_route_policy_tables.php | mid_controls, bin_route_preferences, bin_ranges, platform_fee_rules |
| modules/RoutePolicy/** | Contracts (RouteContext, RoutePolicy), Domain (BinPreference, MidCap), Infrastructure, Application (RoutePolicyService) |
| modules/PlatformFees/** | Contracts (PlatformFees), Domain (PlatformFee), Infrastructure (PlatformFeeRule), Application (PlatformFeeService) |
| app/Http/Controllers/Admin/MidControlController.php, BinRoutingController.php, PlatformFeeController.php | Admin screens |
| resources/views/admin/routing2/mids.blade.php, bins.blade.php, fees.blade.php | Views |
| tests/Unit/RoutePolicyAndFeesTest.php | Tests |

## Edits

1. **app/Providers/AmpliRailServiceProvider.php** — after the StatementService binding add:
   ```php
   $this->app->scoped(\Modules\RoutePolicy\Contracts\RoutePolicy::class, \Modules\RoutePolicy\Application\RoutePolicyService::class);
   $this->app->scoped(\Modules\PlatformFees\Contracts\PlatformFees::class, \Modules\PlatformFees\Application\PlatformFeeService::class);
   ```

2. **modules/Payments/Application/PaymentOrchestrator.php**
   a. Constructor: add `private readonly \Modules\RoutePolicy\Contracts\RoutePolicy $routePolicy,` (after the velocity parameter from round 5).
   b. Replace
   ```php
            $candidates = (new Router)->candidates($this->accounts->routable($c->merchantId, $c->environment), $c->merchantId, $c->amount->currency, $c->amount->minor);
            if ($candidates === []) {
   ```
   with
   ```php
            $candidates = (new Router)->candidates($this->accounts->routable($c->merchantId, $c->environment), $c->merchantId, $c->amount->currency, $c->amount->minor);
            $candidates = $this->routePolicy->order($candidates, new \Modules\RoutePolicy\Contracts\RouteContext($c->merchantId, $c->environment,
                $c->amount->minor, $c->amount->currency, isset($c->card['bin']) ? (string) $c->card['bin'] : null, $c->card['brand'] ?? null, $c->card['type'] ?? null));
            if ($candidates === []) {
   ```

3. **modules/Settlement/Application/StatementService.php**
   a. Imports: `use Modules\PlatformFees\Contracts\PlatformFees;` Constructor: add `private readonly PlatformFees $platformFees,` after `DisputeReports $disputes,`.
   b. In `draft()`, after the "Chargebacks won in the period" block, add:
   ```php
        // Partner markups (platform fees) from active rules in the statement currency.
        $platform = $this->platformFees->forStatement($merchantId, $schedule->currency, $gross, $approvedCount);
        foreach ($platform as $pf) {
            $result['lines'][] = ['section' => 'fees', 'label' => 'Platform fee: '.$pf['partner_name'], 'amount' => Money::of(-$pf['amount_minor'], $schedule->currency)];
            $result['net'] = $result['net']->minus(Money::of($pf['amount_minor'], $schedule->currency));
        }
   ```
   c. In the `PayoutStatement::query()->create([` call, replace `'input' => get_object_vars($input),` with
   `'input' => get_object_vars($input) + ['platform_fees' => $platform],`
   d. In `execute()`, after the reserve `if` block and before `if ($lines !== [])`, add:
   ```php
        foreach ((array) ($s->input['platform_fees'] ?? []) as $pf) {
            $amt = (int) ($pf['amount_minor'] ?? 0);
            if ($amt > 0) {
                $lines[] = ['merchant_payable:'.$s->merchant_id, Money::of($amt, $c)];
                $lines[] = ['partner_payable:'.$pf['partner_key'], Money::of(-$amt, $c)];
            }
        }
   ```

4. **routes/web.php** — imports for the three controllers; in the admin group:
   ```php
   Route::get('mids', [MidControlController::class, 'index'])->middleware('permission:routing.view')->name('mids.index');
   Route::post('mids/{mid}', [MidControlController::class, 'update'])->middleware('permission:merchants.limits.edit')->name('mids.update');
   Route::get('bin-routing', [BinRoutingController::class, 'index'])->middleware('permission:routing.view')->name('bins.index');
   Route::post('bin-routing', [BinRoutingController::class, 'store'])->middleware('permission:routing.draft')->name('bins.store');
   Route::post('bin-routing/import', [BinRoutingController::class, 'import'])->middleware('permission:routing.draft')->name('bins.import');
   Route::post('bin-routing/{pref}/toggle', [BinRoutingController::class, 'toggle'])->middleware('permission:routing.draft')->name('bins.toggle');
   Route::get('platform-fees', [PlatformFeeController::class, 'index'])->middleware('permission:fees.view')->name('platform-fees.index');
   Route::post('platform-fees', [PlatformFeeController::class, 'store'])->middleware('permission:fees.manage')->name('platform-fees.store');
   Route::post('platform-fees/{rule}/toggle', [PlatformFeeController::class, 'toggle'])->middleware('permission:fees.manage')->name('platform-fees.toggle');
   ```

5. **layouts/admin.blade.php** — `'Payments'` group: `['admin.mids.index', 'MID management', 'routing.view'],`, `['admin.bins.index', 'BIN routing', 'routing.view'],`;
   `'Money'` group: `['admin.platform-fees.index', 'Platform fees', 'fees.view'],`

Then: `php artisan migrate --database=pgsql_owner --force && php artisan optimize:clear`

## Rules built in
- MID caps and pauses and BIN preferences only filter or ORDER the merchant's own eligible MIDs (AGENTS.md rules 11, 15, 21):
  no other merchant's MID, no rerouting of declines, no splitting traffic to dodge network monitoring.
- BIN data is prefixes only (6 to 8 digits); longer values are rejected on import.
- Platform fees come from versioned rules on statements, and must be covered by the merchant's signed agreement (rule 18).
