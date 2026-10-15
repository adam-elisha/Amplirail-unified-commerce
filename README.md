# AmpliRail 2.0: unified commerce (v1.1.6)

Property of AmpliPay Group Inc. Confidential.

Processor-neutral payments orchestration for eDataPay: the two-week **launch slice** plus the **Sprint 0.5 4-Day Merchant
Activation Engine** (AR-168 to AR-174, V3.7.1). Laravel 12 / PHP 8.3 modular monolith on PostgreSQL 16 and Redis 7, wrapping
the tested core package (`packages/amplirail-core`, v1.0.0, unchanged, 43/43 tests).

Start here: **[INSTALL.md](INSTALL.md)** (install on a server or locally) · [docs/IMPLEMENTATION_STATUS.md](docs/IMPLEMENTATION_STATUS.md)
(what is built, ticket by ticket, and what only humans can unblock) · [AGENTS.md](AGENTS.md) and [SECURITY.md](SECURITY.md) (rules).

## What is in this repository

| Area | Where | What it does |
| --- | --- | --- |
| Core engine (frozen) | `packages/amplirail-core` | Money, connector contract, simulator, NMI and Gattera bridges, router, ledger validation, payout statement calculator, webhook signer, signed links |
| Tenancy | `modules/Tenancy` | TenantContext, fail-closed Eloquent scope, PostgreSQL row-level security binding |
| Identity | `modules/Identity` | Users, roles (master doc 5.3), Argon2id, admin authenticator app (TOTP), merchant emailed 8-digit codes, lockout, sign-in log, API keys (arpub_/arsec_), OAuth2 client credentials for the CRM |
| Onboarding (Sprint 0.5) | `modules/Onboarding` | AR-168 CRM handoff + Day 0 sandbox, AR-171 state machine + 12 events, AR-169 document checklist, KYB, RoboRisk via RiskDecision, AR-172 go-live gate, AR-173 SLA command center |
| Routing | `modules/Routing` | Merchant accounts (MIDs), AR-170 versioned eligibility engine |
| Payments | `modules/Payments` | Sale, authorize, capture, void, refund; idempotency; screening; failover; never reroutes declines |
| Ledger | `modules/Ledger` | Append-only double-entry postings (validated by the core ledger) |
| Events | `modules/Events` | Transactional outbox, relay to Redis Streams, idempotent consumers |
| Webhooks | `modules/Webhooks` | Signed delivery, 72-hour backoff, auto-disable, replay, SSRF guard |
| Approvals | `modules/Approvals` | Dual control: requester never approves, permission + step-up + note |
| Settlement | `modules/Settlement` | Fee schedules, signed agreements, weekly payout statements, CSV/PDF/XLSX |
| Merchant products | `modules/MerchantProducts` | Signed payment links and the hosted pay page |
| Connectors | `connectors/` | Registry over core connectors, HTTP client + circuit breaker, secrets (AWS), KYB/Risk/Boarding simulators |
| Portals | `app/Http`, `resources/views` | Admin console (11 screens), merchant portal (8 screens), sign-in, pay page |
| API | `routes/api.php`, `openapi/amplirail-v1.yaml` | /v1 REST API (contract) |
| Database | `database/migrations` | Partitioned high-volume tables, RLS, append-only and immutability triggers, least-privilege grants |
| Deploy | `deploy/`, `.github/workflows` | Dockerfile, local compose, Ubuntu single-server installer, CI (SQLite + PostgreSQL), dev/prod deploy |

## Verified results (October 5, 2026, PHP 8.3.33, PostgreSQL 16.15)

| Check | Result |
| --- | --- |
| Core package `php packages/amplirail-core/tests/run.php` | 43/43 passed, SHA256SUMS match |
| Full suite on SQLite `composer test` | 220 passed (1,975 assertions) |
| Feature + PostgreSQL controls `composer test:pgsql` | 67 passed (580 assertions): RLS forced, partitions, triggers, grants |
| PHPStan level 8 `composer stan` | No errors (no baseline, no ignores) |
| Architecture (module boundaries, strict types) `composer arch` | Passed |

## Everyday commands

```bash
composer test          # core + full Pest suite (SQLite)
composer test:pgsql    # feature + PostgreSQL-only controls against real PostgreSQL
composer stan          # PHPStan level 8
composer arch          # module boundary tests
php artisan amplirail:demo   # local only: synthetic demo merchants and simulator payments
```
