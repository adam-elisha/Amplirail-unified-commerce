# Implementation status (v1.1.0, October 5, 2026)

Scope delivered: the application layer the code-package status sheet listed as "still to build" (Laravel app, migrations,
sign-in, screens, API endpoints, background jobs), plus the V3.7.1 Sprint 0.5 activation engine (AR-168 to AR-174).
Everything below marked **Done** is covered by automated tests that pass on SQLite and on PostgreSQL 16.

Legend: **Done** = built and tested · **Partial** = built, with the stated gap · **Human** = needs a person, account or
decision, not code · **Next** = follow-up ticket.

## Sprint 0.5: 4-Day Merchant Activation Engine (V3.7.1)

| Ticket | Status | Where | Notes |
| --- | --- | --- | --- |
| AR-174 ADR: RoboRisk boundary and sandbox state | **Human** (written, Proposed) | `docs/adr/ADR-0004-roborisk-boundary-and-sandbox-state.md` | Code follows the stated defaults. Mark Accepted after the Lead PHP Developer and Adam approve. |
| AR-168 CRM handoff + Day 0 provisioning | **Done** | `modules/Onboarding/Application/HandoffService.php`, `POST /v1/merchants/handoff` | Idempotent by key and by `crm_deal_id`; one transaction; simulator MID, `arpub_sbx_`/`arsec_sbx_`, `arwhk_sbx_`; OAuth2 client credentials (`crm.handoff`); PAN and raw bank fields rejected; EIN/owners/contacts field-encrypted, EIN fingerprint; flag `crm_handoff`. |
| AR-171 Event catalog + state machine | **Done** | `modules/Onboarding/Domain/MerchantStateMachine.php`, `Application/MerchantLifecycle.php` | One state machine; 12 events written in the same transaction as the state change; Section 11 envelope; replays are no-ops; illegal jumps throw, log and audit; direct state writes blocked. |
| AR-169 Document checklist engine | **Done** | `modules/Onboarding/Application/DocumentChecklist.php` | Data-driven requirements; content sniffing; random storage names; app-level encryption plus S3 SSE-KMS in AWS; signed 5-minute audited downloads; processor more-info reopens the checklist and resumes boarding. |
| AR-170 Processor eligibility engine | **Done** | `modules/Routing/Domain/EligibilityEngine.php`, `Application/EligibilityService.php` | 12 rule types in fixed precedence; ordered eligible routes; specific failing rules with expected/actual; versioned, effective-dated, published only through Approvals; before/after audited. Seeded v1 route limits are starting points for Operations/Risk to confirm. |
| AR-172 Go-live gate, dual control | **Done** | `modules/Onboarding/Application/GoLiveGate.php`, `modules/Approvals` | 10 live checks, each fail-closed; requester never approves; approver needs `merchants.go_live.approve`, authenticator step-up and a note; flag off = nobody activates; Platform Owner emergency override needs a second human and is audited. Live API keys issued only at activation. |
| AR-173 SLA command center | **Done** | `modules/Onboarding/Application/CommandCenter.php`, admin Overview, `GET /v1/onboarding/command_center` | Pipeline with day-over-day change, blocker/owner/next action/deadline, at-risk and overdue rows, 90-day median/average time in state and time to live, connector and webhook health, labelled sample-data mode. |

## Sprint 0 and the two-week launch slice

| Ticket | Status | Notes |
| --- | --- | --- |
| AR-001 Company GitHub org | **Human** | MOHIT_START_GUIDE Part A. Add the Lead PHP Developer to `@amplirail/rail-owners` in CODEOWNERS. |
| AR-002 CRM card-data audit | **Human** | Report on the existing CRM. |
| AR-003 Starter pack | **Done** | AGENTS.md, CLAUDE.md, SECURITY.md, docs, prompts, templates in the repo. |
| AR-004 Laravel 12 skeleton, modules | **Done** | `modules/*` with Domain/Application/Infrastructure/Contracts; strict types; PHPStan level 8 clean; Pest. |
| AR-005 CI | **Partial** | Tests (SQLite + PostgreSQL), PHPStan, architecture, contract group, audit, gitleaks, OpenAPI lint. **Next**: Semgrep, OpenAPI diff against main, pin action SHAs. |
| AR-006 Terraform | **Next** | INSTALL.md Option C lists exactly what the app expects (RDS, ElastiCache, Secrets Manager names, S3/KMS, ECS services). |
| AR-007 Tenancy | **Done** | TenantContext; fail-closed scope; PostgreSQL RLS FORCED on 26 tables; cross-tenant tests by API, UI and raw SQL. |
| AR-008 Identity | **Partial** | Argon2id, admin authenticator (TOTP, replay-protected), merchant emailed 8-digit codes (hash, 10 min, single use, 45 s resend, 5/hour), lockout 5/30 min, 15-min admin idle timeout, new-device alerts, sign-in log, invitations. **Next**: WebAuthn security keys (super admin, Phase 2), company SSO, self-service "forgot password" (today an admin re-sends the invitation, which resets the password). |
| AR-009 Audit and outbox | **Done** | Immutable audit (trigger + grants); outbox in the same transaction; relay to Redis Streams with SKIP LOCKED; idempotent consumers (`processed_events`). |
| AR-010 Schemas, OpenAPI | **Done** | `openapi/amplirail-v1.yaml`. |
| AR-011 / AR-012 Connector contract, simulator | **Partial** | Core contract and simulator; flags `sim_timeout`, `sim_outage`, `sim_avs_fail` added. **Next**: "amount 1.23 = partial approval" needs a partial-amount field in the core ConnectorResult (core change, new core version). |
| AR-013 Backups and restore test | **Human** | AWS Backup plan; restore test with the ledger balance SQL in `docs/ACCEPTANCE.md`. |
| AR-014 PCI data flow | **Done** | `docs/PCI_DATA_FLOW.md`. |
| AR-015 Elavon SE package | **Human** | |
| AR-016 to AR-019 NMI bridge | **Partial** | Core connector wired with secrets-manager credentials per MID, circuit breaker, hosted-field pay page glue (`public/js/pay.js`). **Blocked on NMI access**: verify field names against `docs/providers/nmi/`; **Next**: transaction sync and inbound provider webhooks (reconcile `unknown` outcomes). |
| AR-125 Gattera bridge | **Blocked** | Placeholder connector disabled until Gattera documentation and hosted fields/tokens are confirmed. |
| AR-020/052/053 Merchant setup, MIDs, virtual terminal, links | **Partial** | Manual add (same package as CRM), MIDs per provider, signed payment links with pay page, sandbox virtual terminal. **Next**: live virtual-terminal hosted fields (after NMI access). |
| AR-146 to AR-150 Payout statements | **Partial** | Fee schedules, signed agreements gate, weekly drafts from real transactions (core calculator, numbers match the core test), dual-control issue, fee and reserve ledger journals, CSV/PDF/XLSX, immutable once issued. **Next**: PSP payout report import and expected-vs-received reconciliation; reserve release schedule; chargebacks (disputes module). |
| AR-057 Portal screens | **Done (launch set)** | Admin: Overview, Approvals, Merchants, Add merchant, Merchant 360, Transactions + detail, Payout statements, Routing and eligibility, Management, Developers and system. Merchant: Dashboard, Transactions + detail, Virtual terminal, Payment requests, Payout statements, Documents, Developers and API keys. Sign-in and verification, pay page. |
| AR-161 Partitioned tables | **Done** | Monthly partitions + DEFAULT for transactions, attempts, ledger, outbox; daily `amplirail:partitions:ensure`. |
| AR-165 Load test 200/s | **Human** | `packages/amplirail-core/loadtest/sale_200rps.js` against staging with the simulator; then run the SQL checks in `docs/ACCEPTANCE.md`. |

## Known limitations to schedule

1. Risk limits, reserve and descriptor changes after go-live must go through Approvals: the screen blocks direct edits for live merchants; the approval flow for those changes is not built yet.
2. Disputes and chargebacks (Section 9.6) are not in this release; statements carry zero chargeback lines.
3. Provider-side reconciliation of `unknown` outcomes (timeouts with possible provider records) is manual until transaction sync exists.
4. The KYB, RiskDecision and MerchantBoarding connectors are simulators. Real vendors plug in behind the same interfaces in `connectors/`.
5. A document is written to storage before its database row; a failed transaction can leave an unreferenced encrypted blob (harmless; add a weekly orphan sweep).

## What only humans can unblock (from V3.7.1 Section 14)

NMI sandbox and production API access · Gattera documentation and sandbox · counsel-approved merchant and fee agreement
wording and the Terms/Privacy text (footers show `[Terms]`/`[Privacy]` placeholders) · pilot merchant list · Elavon SE
session · the Lead PHP Developer's GitHub username in CODEOWNERS · ADR-0004 approval · Platform Owner authorization of the
first production deploy.
