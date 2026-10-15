# Two-week launch slice

Goal: real merchants processing on AmpliRail within two weeks, on the NMI bridge (US, with its existing processor connections) and the Gattera bridge (EU acquiring banks), operating under the providers' PCI Level 1 certificates (D19 Track A) until AmpliPay's own certificate is issued. More providers are added as soon as the Level 1 certificate is in hand.

| Day | Deliverable | Tickets |
| --- | --- | --- |
| 1 | Repository, CI, staging environment, secrets manager | AR-001, AR-003 to AR-006 |
| 2 | Tenancy, identity with 2-factor, audit and outbox | AR-007 to AR-009 |
| 3 | Connector contract, simulator, contract tests; partitioned tables | AR-011, AR-012, AR-161 |
| 4 to 5 | NMI bridge connector with hosted card fields: sale, authorize, capture, refund, void; transaction sync and provider webhooks | AR-016 to AR-019 (NMI scope) |
| 5 to 6 | Gattera bridge connector (hosted fields or tokens only), EUR and other EU currencies | AR-125 |
| 6 to 7 | Merchant setup (manual), MIDs per provider, virtual terminal, payment links and invoices | AR-020, AR-052, AR-053 |
| 8 to 9 | Payout statements: fee schedules, weekly statements, reserve ledger, PSP reconciliation, PDF, CSV and Excel | AR-146 to AR-150 |
| 9 | Merchant portal: dashboard, transactions, statements; admin: merchants, approvals, reports | AR-057 and screens |
| 10 | Security review, load test at 200 per second on the simulator, backup restore test | AR-013, AR-165 |
| 11 to 12 | Pilot with test merchants, fixes | n/a |
| 13 to 14 | Go-live for first merchants; daily reports | n/a |

Not in the launch slice (next, in order): processor-direct and other providers after the Level 1 certificate; POS, members and folios; commerce CRM; rewards and vouchers; currency conversion; Model B payouts; AmpliRail Switch.

Prerequisites from Adam: NMI sandbox and production API access for the team; Gattera API documentation and sandbox; counsel-approved merchant and fee agreement wording; list of first pilot merchants.

## Daily Claude Code prompt (one session per ticket)

```
Read AGENTS.md, CLAUDE.md and docs/MASTER_DEVELOPMENT_DOCUMENT_V3.md (Sections 1, 4.5, 7, 9.9 and 19.1).
Today's ticket: <AR-number> from docs/LAUNCH_SLICE_2_WEEKS.md.
Work on branch feature/<AR-number>. Write tests first. Use the simulator connector for all tests.
Never put secrets, card numbers or production data in code, prompts or logs.
Open a pull request with the report template filled in. Do not merge.
```

## Daily status (Mohit to Adam, every evening)

```
Date:
Done today (tickets, PR links):
Tomorrow:
Blocked by (who, what):
Risks to the launch date:
Test results (tests passed, load test numbers if run):
```
