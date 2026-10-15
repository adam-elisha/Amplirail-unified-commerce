# AmpliRail round 5: velocity limits, pre-dispute alerts (Ethoca / Verifi) and chargeback management — install guide

Built 2026-10-14. Install after rounds 1 to 4. Copy files at the same paths, make the edits below (back up each file first),
then migrate.

## New files

| File | What it is |
| --- | --- |
| database/migrations/2026_10_14_000500_create_velocity_rules_table.php | Velocity rules (RLS) |
| database/migrations/2026_10_14_000600_create_dispute_alerts_tables.php | dispute_alerts, alert_settings, dispute_documents (RLS) |
| modules/Velocity/** | Contracts (VelocityInput, VelocityCheck), Domain (VelocityRule), Infrastructure, Application (VelocityEngine) |
| modules/Disputes/Infrastructure/DisputeAlert.php, AlertSetting.php, DisputeDocument.php | Models |
| app/Services/AlertResponder.php | Matches an alert to the sale and fast-refunds per the merchant's setting |
| app/Http/Controllers/Api/AlertApiController.php | POST /v1/alerts for alert partners |
| app/Http/Controllers/Admin/VelocityController.php, AlertController.php, DisputeAnalyticsController.php | Admin screens |
| app/Http/Controllers/Merchant/MerchantAlertController.php | Merchant alerts and fast-refund choice |
| app/Http/Controllers/DisputeEvidenceController.php | Evidence uploads (both portals) |
| resources/views/admin/risk/velocity.blade.php, admin/disputes/alerts.blade.php, admin/disputes/analytics.blade.php | Admin views |
| resources/views/merchant/disputes/alerts.blade.php, merchant/disputes/partials/documents.blade.php | Merchant views, shared evidence panel |
| tests/Unit/VelocityRuleTest.php | Tests |

## Edits

1. **app/Providers/AmpliRailServiceProvider.php** — after `$this->app->scoped(StatementService::class);` add:
   ```php
   $this->app->scoped(\Modules\Velocity\Contracts\VelocityCheck::class, \Modules\Velocity\Application\VelocityEngine::class);
   ```

2. **modules/Payments/Application/PaymentOrchestrator.php** (velocity in the payment flow)
   a. Constructor: after `private readonly Cache $cache,` add `private readonly \Modules\Velocity\Contracts\VelocityCheck $velocity,`
   b. In `charge()`, directly after the existing screening block
      (`if (! $screen['allow']) { ... }`), add:
   ```php
            $vin = new \Modules\Velocity\Contracts\VelocityInput($c->merchantId, $c->environment, $c->amount->minor, $c->amount->currency,
                hash('sha256', $c->paymentToken), isset($c->card['bin']) ? (string) $c->card['bin'] : null, $c->card['brand'] ?? null);
            if ($this->velocity->check($vin) !== null) {
                return $this->persistCharge($c, $scope, 'blocked', 'blocked', null, null, 'velocity_limit', [], $profile);
            }
   ```
   c. At the top of `foreach ($candidates as $mid) {` add:
   ```php
                if (! $this->velocity->allowsConnector($vin, $mid->connector)) {
                    continue; // processor limit reached for this route; no provider call was made
                }
   ```
   (Hosted fields should return `bin` with `brand` and `last4` in the card display data for BIN rules to apply. First 6 to 8
   digits plus last 4 may be stored under PCI DSS; never the full number.)

3. **routes/web.php** — admin imports: `VelocityController`, `AlertController`, `DisputeAnalyticsController`; plus
   `use App\Http\Controllers\DisputeEvidenceController;` and `use App\Http\Controllers\Merchant\MerchantAlertController;`.
   In the admin group:
   ```php
   Route::get('risk/velocity', [VelocityController::class, 'index'])->middleware('permission:risk.view')->name('risk.velocity');
   Route::post('risk/velocity', [VelocityController::class, 'store'])->middleware('permission:merchants.limits.edit')->name('risk.velocity.store');
   Route::post('risk/velocity/{rule}/toggle', [VelocityController::class, 'toggle'])->middleware('permission:merchants.limits.edit')->name('risk.velocity.toggle');
   Route::get('alerts', [AlertController::class, 'index'])->middleware('permission:payments.view')->name('alerts.index');
   Route::post('alerts/settings', [AlertController::class, 'settings'])->middleware('permission:payments.refund')->name('alerts.settings');
   Route::post('alerts/manual', [AlertController::class, 'manual'])->middleware('permission:payments.refund')->name('alerts.manual');
   Route::post('alerts/client', [AlertController::class, 'createClient'])->middleware('permission:settings.security.manage')->name('alerts.client');
   Route::get('disputes-analytics', [DisputeAnalyticsController::class, 'index'])->middleware('permission:payments.view')->name('disputes.analytics');
   Route::post('disputes/{dispute}/evidence', [DisputeEvidenceController::class, 'store'])->middleware('permission:payments.refund')->name('disputes.evidence');
   ```
   (Put `disputes-analytics` as written: a separate path so it never clashes with `disputes/{dispute}`.)
   In the merchant group:
   ```php
   Route::get('alerts', [MerchantAlertController::class, 'index'])->middleware('permission:payments.view')->name('alerts.index');
   Route::post('alerts/settings', [MerchantAlertController::class, 'settings'])->middleware('permission:payments.refund')->name('alerts.settings');
   Route::post('disputes/{dispute}/evidence', [DisputeEvidenceController::class, 'store'])->middleware('permission:payments.refund')->name('disputes.evidence');
   ```

4. **routes/api.php** — add:
   ```php
   Route::middleware(['auth.service:alerts.write', 'throttle:api'])->group(function (): void {
       Route::post('alerts', [\App\Http\Controllers\Api\AlertApiController::class, 'store'])->name('api.alerts.store');
   });
   ```

5. **Navigation**
   - layouts/admin.blade.php, `'Payments' => [` group, after Disputes and chargebacks:
     `['admin.alerts.index', 'Pre-dispute alerts', 'payments.view'],`, `['admin.disputes.analytics', 'Chargeback outcomes', 'payments.view'],`,
     `['admin.risk.velocity', 'Velocity and limits', 'risk.view'],`
   - layouts/merchant.blade.php, after Disputes: `['merchant.alerts.index', 'Dispute alerts', 'payments.view'],`

6. **Evidence panel on dispute pages** — in `resources/views/admin/disputes/show.blade.php`, after the "Merchant response" section add
   `@include('merchant.disputes.partials.documents', ['d' => $d, 'portal' => 'admin'])`; in
   `resources/views/merchant/disputes/show.blade.php`, after the "Your response" block add
   `@include('merchant.disputes.partials.documents', ['d' => $d, 'portal' => 'merchant'])`.

7. **app/Console/Commands/CreateServiceClientCommand.php** — add `'alerts.write'` to `$allowed` (so the CLI can also create alert partners).

Then: `php artisan migrate --database=pgsql_owner --force && php artisan optimize:clear`

## Alert partner API (give this to Ethoca / Verifi resellers)

1. `POST /v1/oauth/token` with `grant_type=client_credentials`, `client_id`, `client_secret` (created on Pre-dispute alerts).
2. `POST /v1/alerts` with a bearer token:
   ```json
   {"provider": "ethoca", "alert_id": "ETH-123", "amount_minor": 4999, "currency": "USD",
    "transaction_id": "txn_...", "provider_reference": null, "card_last4": "4242", "transaction_date": "2026-10-10", "alert_type": "fraud"}
   ```
   Identify the sale by `transaction_id`, or `provider_reference` (processor transaction ID), or `card_last4` + `transaction_date` + amount
   (used only if exactly one sale matches). Response: `outcome` = refunded, already_refunded, chargeback_open, declined, not_found,
   refund_failed or rdr_resolved. Repeating the same alert returns the first answer and never refunds twice.
