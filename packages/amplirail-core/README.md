# AmpliRail Core (v1.0.0-launch)

Property of AmpliPay Group Inc. Confidential. Tested core code for the AmpliRail launch slice.

| Folder | Contents |
| --- | --- |
| src/Money | Money in integer minor units with currency |
| src/Connectors | Connector contract, Simulator, NMI bridge, Gattera bridge |
| src/Payments | Transaction orchestration and idempotency |
| src/Routing, src/Screening | Merchant's-own-MID routing; limits, blocks, velocity |
| src/Ledger, src/Events | Append-only double-entry ledger; transactional outbox |
| src/Webhooks, src/Links | Signed webhooks; signed payment and invoice links |
| src/Payouts | Payout statement calculator, fee schedules, CSV export |
| database/ | High-volume partitioned PostgreSQL schema with tenant isolation |
| openapi/ | Launch API specification |
| loadtest/ | k6 load test (200 per second gate) |
| deploy/ | Dockerfile, local Docker Compose, settings template |
| .github/workflows/ | CI, deploy to development, approved deploy to production |
| tests/ | 43 tests (run without any dependencies) |

See INSTALL.md to install and verify.
