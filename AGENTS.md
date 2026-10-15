# AGENTS.md: operating rules for every AI agent in this repository

You are building AmpliRail 2.0, a processor-neutral payments orchestration platform for eDataPay / AmpliPay. Money and card data are involved. Correctness and security outrank speed.

## Read first, every session

1. This file.
2. docs/MASTER_DEVELOPMENT_DOCUMENT_V3.md (the controlling document; it wins any conflict).
3. SECURITY.md.
4. The ticket you were given (docs/TASK_TEMPLATE.md format) and its row in docs/BACKLOG.md.

If the ticket conflicts with the master document, stop and report the conflict. Do not guess.

## Hard rules (never break)

1. Never store, log, print or pass raw card numbers (PAN) or CVV through AmpliRail. Card entry uses processor-hosted fields only.
2. Never put secrets in code, config committed to git, tests, fixtures, logs, comments or your replies. Use Secrets Manager references.
3. Never use real customer, merchant or card data. Use synthetic data and simulator test cards.
4. Money is integer minor units plus ISO 4217 currency. No floats for amounts.
5. The ledger is append-only. Never write UPDATE or DELETE against ledger entries.
6. Every mutating endpoint requires an idempotency key.
7. Every state change goes through the state machine and writes an outbox event in the same transaction.
8. Every query is tenant-scoped. Code without a TenantContext must fail closed.
9. A module never imports another module's internals. Use its Contracts or events.
10. Provider-specific fields stay inside connectors/.
11. Never retry a hard issuer decline on another route.
12. Never weaken, skip or delete a test, lint rule or CI check to make something pass.
13. Never push to main, deploy, create live credentials, publish routing policy, move money or change RLS or permissions without a human.
14. Regulated features (consumer wallet, card issuing, dynamic descriptors, gaming geofencing, gift cards, stablecoins) are built behind feature flags that stay off in production until a human enables them. Never write code that holds crypto private keys.
15. Call stored cards Secure Card Storage (SCS). Never use the word "vault" in UI text, API names, code, tables or docs (third-party trademark); use scs, card_storage or token storage instead.
16. Use neutral AmpliRail terms only. Never use other payment companies' product or object names (for example Radar, Connect, PaymentIntent, Smart Retries) or their key prefixes (pk_, sk_, rk_, whsec_). Use Transaction, TransactionAttempt, transaction screening, automatic retries, and key prefixes arpub_, arsec_, arrst_, arwhk_.
17. RoboRiskAnalysis is a separate system: never send it card numbers, CVV or full bank numbers, and never apply its recommendations without the Approvals service.
18. Money in different currencies is never added together without a recorded conversion; use each currency's own decimals. Fees come only from versioned fee rules, and a fee rule never applies before the payer's agreement for that version is signed.
19. Clean room: never copy third-party gateway documentation, endpoint names, field names, product names or screens into AmpliRail. Provider docs are used only inside that provider's connector folder (docs/providers/<provider>/).
20. Card-present integrity: pass the device's EMV or contactless data to the processor unchanged; never convert card-present to keyed or e-commerce.
21. MID routing: route only to MIDs boarded for the same merchant, business name and descriptor; block and log anything else.
22. Use only approved AI tools (master document Section 18.1); never paste AmpliRail code or data into unapproved hosted AI services.
23. Never claim something is secure, compliant or tested unless you ran the check and show the output.

## How you work on a ticket

1. Restate the goal, list the files you will create or change, and list anything unclear. Wait for approval if the ticket says plan-first.
2. Work on a branch named ar-<ticket number>-<short-name>.
3. Write or update tests first where practical.
4. Implement the smallest change that meets the acceptance criteria.
5. Run: composer test, composer stan, composer arch, composer contract, composer audit, and gitleaks. Paste the summarized results.
6. Update openapi/ for any API change and docs/ where behavior changed.
7. Open a pull request using .github/pull_request_template.md. Fill every section honestly.

## Code conventions

- Laravel 12, PHP 8.3+, strict_types on every file, PHPStan level 8+.
- Module layout: Domain (no framework code), Application (use cases), Infrastructure (Eloquent, adapters), Http (controllers, requests, resources), Contracts (public interfaces), Tests.
- IDs: prefixed ULIDs (mer_, txn_, tat_, rf_, led_, stl_, po_, evt_, usr_, org_, tkt_, dev_, whk_).
- Validation in FormRequest classes. Authorization in Policies. No business logic in controllers.
- Structured logs with correlation_id. Mask PII.
- Errors return the single error shape with request_id. No stack traces to clients.

## When you are unsure

Stop and ask. Write the question in your reply with the options you see and the one you recommend. Never invent provider API behavior; if documentation is not in the repo, say so.
