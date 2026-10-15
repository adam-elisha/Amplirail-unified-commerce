# AmpliRail round 4: network fraud reports and Visa VAMP monitoring — install guide

Built 2026-10-14. Install after round 3 (or on its own). Copy the files at the same paths, make 2 edits, migrate.

## New files

| File | What it is |
| --- | --- |
| database/migrations/2026_10_14_000400_create_network_fraud_reports_table.php | Append-only TC40 / SAFE reports, RLS, runtime role cannot update or delete |
| modules/Monitoring/Domain/VampCalculator.php | VAMP ratio, merchant and acquirer thresholds (one place to update) |
| modules/Monitoring/Infrastructure/FraudReport.php | Model (append-only) |
| app/Services/VampMonitor.php | Monthly per-merchant and portfolio figures; CSV import |
| app/Http/Controllers/Admin/RiskMonitoringController.php | Risk screen and import |
| resources/views/admin/risk/vamp.blade.php | Screen |
| tests/Unit/VampCalculatorTest.php | Calculator tests |

## Edits

1. **routes/web.php** — add `use App\Http\Controllers\Admin\RiskMonitoringController;` and in the admin group:
   ```php
   Route::get('risk/vamp', [RiskMonitoringController::class, 'index'])->middleware('permission:risk.view')->name('risk.vamp');
   Route::post('risk/vamp/import', [RiskMonitoringController::class, 'import'])->middleware('permission:risk.decide')->name('risk.vamp.import');
   ```
2. **resources/views/layouts/admin.blade.php** — in the `'Payments' => [` group add:
   `['admin.risk.vamp', 'Network monitoring (VAMP)', 'risk.view'],`

Then: `php artisan migrate --database=pgsql_owner --force && php artisan optimize:clear`

## Rules built in

- Ratio = (Visa TC40 + disputes) / settled card-not-present Visa sales per month; a sale with both counts once.
- Merchant excessive: 1.5% (US, Canada, EU, Asia Pacific, since April 2026) or 2.2% (other regions), with at least 1,500 events.
  Early warning at 75% of the threshold. Portfolio: 0.50% above standard, 0.70% excessive.
- The monitor never reroutes sales. Moving a merchant's traffic to other MIDs to stay under a monitoring threshold breaks card
  network rules and AGENTS.md rule 21. The response to a breach is a remediation plan, tighter screening or suspension.
