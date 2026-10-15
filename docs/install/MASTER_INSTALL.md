# AmpliRail rounds 1 to 6 — master install guide (2026-10-14)

Project folder on the dev server: `/home/amplidev/amplirail/amplirail-unified-commerce` (getpayments101.amplipay.online).
Each round folder mirrors the project: copy every file to the same path in the project. Then make the edits listed in that
round's INSTALL file. Back up every existing file before you edit it.

## What is already on the server

| Round | Status |
| --- | --- |
| 1 Disputes and chargebacks | Installed and wired |
| 2 Reconciliation, reserves, statement chargebacks, emailed approval codes | Installed and wired |
| 3 Private label | All files uploaded; routes and the brand middleware line in bootstrap/app.php are done. STILL TO DO: edits 2, 3, 4, 5 and 7 of INSTALL_PRIVATE_LABEL.md (layouts, footer, sign-in rule) |
| 4 VAMP / TC40 monitoring | Not installed |
| 5 Velocity, Ethoca/Verifi alerts, evidence uploads, outcomes report | Not installed |
| 6 MID management, BIN routing, platform fees | Not installed |

## Order

1. Round 3: remaining edits only.
2. Round 4, then round 5, then round 6: copy files, make the edits in each INSTALL file.
3. Run, as the site user:
   ```bash
   cd ~/amplirail/amplirail-unified-commerce
   PHP_INI_SCAN_DIR=~/.php83.d /opt/alt/php83/usr/bin/php artisan migrate --database=pgsql_owner --force
   PHP_INI_SCAN_DIR=~/.php83.d /opt/alt/php83/usr/bin/php artisan optimize:clear
   ```
   (In CWP: Service SSH → Send Shell command, wrap it as `su - amplidev -c '...'`.)
   This creates every new table from rounds 1 to 6: disputes, processor_deposits, brands, network_fraud_reports,
   velocity_rules, dispute_alerts, alert_settings, dispute_documents, mid_controls, bin_route_preferences, bin_ranges,
   platform_fee_rules.
4. CWP → User Accounts → Fix Permissions for `amplidev` (files uploaded through CWP belong to root).
5. Run the tests: `composer test` and `composer stan`. New unit tests are in tests/Unit.
6. Make sure a queue worker runs (`php artisan queue:work`), or invitation and code emails are never sent.

## Very important: put everything in Git

Rounds 1 to 3 were made directly on the server. Commit the whole project (minus .env and vendor) to the repository before
the next deploy, or a deploy from the old repository will remove these features.

## Quick check after installing

Sign in to /admin and open: Disputes and chargebacks, Pre-dispute alerts, Chargeback outcomes, Velocity and limits,
Network monitoring (VAMP), MID management, BIN routing, Reconciliation, Reserves, Platform fees, and (platform owner)
Private label partners. Each page should load without an error.
