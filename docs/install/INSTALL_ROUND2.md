# Round 2: reconciliation, reserves, chargebacks on statements, emailed approval codes — ALREADY INSTALLED (2026-10-14)

Files here are exactly what is on the server. Edits already made on the server:
- app/Providers/AmpliRailServiceProvider.php: binds DisputeReports -> DisputeReportService, scoped ReserveService, ReserveService tagged as approval handler.
- modules/Settlement/Application/StatementService.php: DisputeReports injected; chargeback, retrieval and representment counts on drafts;
  "Chargebacks won (returned)" credit line.
- modules/Identity/Application/UserAdministration.php: verify() uses the authenticator only when the admin has one; otherwise the emailed code.
- config/amplirail_roles.php: approvals `'reserve_release' => 'fees.approve'`.
- routes/web.php: recon.index/store/import/resolve, reserves.index/release, step-up.send.
- layouts/admin.blade.php: Money menu Reconciliation, Reserves.
- resources/views/admin/approvals.blade.php: "Email me a code" button, code field accepts 8 digits, reserve release payload.
- bootstrap/cache/config.php renamed to config.php.bak-20261014 (config cache cleared).
Also on the server from earlier: app/Http/Controllers/Auth/SignInController.php has ADMIN_SECOND_STEP = 'off'
(authenticator turned off for development) and auth/sign-in.blade.php says so.
