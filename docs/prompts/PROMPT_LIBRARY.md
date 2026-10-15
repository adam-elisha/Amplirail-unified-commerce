# AmpliRail prompt library

Copy these prompts into Claude Code (or another approved agent) inside the amplirail-unified-commerce repository. Replace anything in angle brackets. Start every new session with P0. Never paste secrets, production data or card data into any prompt.

## P0: Session bootstrap (start of every session)

```
You are working in the AmpliRail repository. Before doing anything else:
1. Read AGENTS.md, docs/MASTER_DEVELOPMENT_DOCUMENT_V3.md and SECURITY.md in full.
2. Summarize in 10 bullets or fewer the hard rules you must follow.
3. List the modules and connectors that currently exist and the CI commands available.
4. Confirm you will not touch secrets, production data or card data, and will work only through a branch and pull request.
Do not write code yet. Wait for the ticket.
```

## P1: Execute a ticket

```
Here is ticket <AR-number>:
<paste the full ticket from docs/TASK_TEMPLATE.md>

Work rules:
- Stay inside the files allowed in the ticket. If you need another file, stop and ask.
- First reply with: your understanding of the goal, the plan, the exact files you will create or change, the tests you will write, and any question or conflict with the master document.
- After I approve, create branch ar-<number>-<short-name>, write tests first where practical, then implement.
- Run composer test, composer stan, composer arch, composer contract, composer audit and gitleaks. Show the summarized output.
- Fix failures properly. Never weaken or skip a test or check.
- Open the pull request using .github/pull_request_template.md and fill every section truthfully.
```

## P2: Scaffold a module

```
Scaffold the <ModuleName> module following master document Section 4.2 and 4.4.
Create Domain/, Application/, Infrastructure/, Http/, Contracts/ and Tests/ folders with:
- The entities and value objects listed in ticket <AR-number>, with prefixed ULID IDs.
- A state machine class if the entity has a lifecycle (Section 6), with a test for every legal and one illegal transition.
- Migrations that include org_node_id, RLS policies and the correct database role grants.
- A Contracts interface other modules may call, and nothing else public.
- An architecture test proving no other module imports this module's internals.
Do not add endpoints unless the ticket asks for them.
```

## P3: Build a connector

```
Build the <Provider> connector in connectors/<Provider> as a Composer package.
Inputs: the provider documentation stored in docs/providers/<provider>/ (do not assume behavior that is not documented; list gaps as questions).
Requirements:
- Implement only the capability interfaces in connectors/Contracts that the provider supports, and write the capability manifest.
- Map every provider response code to a canonical status and decline category in a mapping table with tests.
- Timeouts, exponential backoff on network errors only, and a circuit breaker.
- Masked request/response logging keyed by attempt ID. Never log PAN, CVV or credentials.
- Credentials loaded by reference from Secrets Manager.
- Pass the shared ContractTests suite and add provider sandbox tests that run only in staging.
- Card data must never reach our servers: use the provider's hosted fields or token APIs only.
Stop and ask before implementing anything that would bring raw card data into scope.
```

## P4: Write tests

```
For <file or feature>, write tests that prove the acceptance criteria in ticket <AR-number>.
Include: happy path, validation failures, authorization failures, cross-tenant access attempts (must fail), idempotency replay, and failure modes (timeouts, provider errors, unknown states).
For money: assert integer minor units and balanced ledger postings.
Use synthetic data and simulator test cards only. Do not change production code unless a test reveals a real bug; if so, report the bug separately.
```

## P5: Security review (approved eDataPay standard prompt)

```
Act as a senior application-security reviewer for this eDataPay project. Perform a defensive security review of the code and configuration that are actually accessible to you. Do not claim to have tested anything you cannot access or execute.

1) INVENTORY & SCOPE FIRST
- Identify the application/framework, major components, authentication/authorization model, data stores, APIs, external services, deployment/config files, dependency manifests and tests that you can inspect.
- State clearly what is NOT accessible or NOT testable (for example production server configuration, cloud/IAM, database grants, firewall/WAF, DNS, CI/CD settings, third-party dashboards, secrets manager, live environment or external provider configuration).

2) REVIEW
Review for OWASP Top 10:2025: Broken Access Control; Security Misconfiguration; Software Supply Chain Failures; Cryptographic Failures; Injection; Insecure Design; Authentication Failures; Software or Data Integrity Failures; Security Logging & Alerting Failures; and Mishandling of Exceptional Conditions. Also inspect eDataPay-specific concerns: role/tenant isolation; secrets exposure; payment-data handling; API/webhook authentication and replay protection; input validation; file uploads; session/cookie security; CSRF/CORS/security headers; database least privilege assumptions; logging of sensitive data; dependency risk; CI/CD/deployment risks visible in the project; error handling; rate limiting; idempotency/retry behavior; and backup/rollback assumptions visible in configuration.

3) FINDINGS
For each finding provide: ID, severity (Critical/High/Medium/Low), affected file/component and location, security impact, evidence/reasoning, and a concrete remediation. Avoid speculative findings: label uncertainty explicitly.

4) REMEDIATION
Where safe and within the accessible project, implement the smallest secure fix that preserves intended behavior. Do not silently redesign payment, authentication, authorization, database, infrastructure, or third-party integration behavior. Flag high-impact architectural changes for human approval. Never insert real credentials or weaken a control to make a test pass.

5) VERIFY
For every implemented fix, run or add an appropriate test/validation when execution is available. Record the exact verification performed and whether it passed. Re-review the changed code for regressions. A code change without verification must remain "Open - Fix Applied, Verification Pending," not "Verified Fixed."

6) FINAL REPORT
Return a concise executive summary followed by a table with these statuses only:
- VERIFIED FIXED: remediation applied and verification passed.
- OPEN / ACTION REQUIRED: unresolved, partially resolved, requires approval, or verification failed/pending.
- NOT TESTED / ACCESS REQUIRED: cannot be validated with current access.
Columns: ID | Status | Severity | OWASP/Control | Component | Finding | Remediation/Current State | Verification Evidence | Owner/Next Step.
End with: (a) tests/scans executed and results, (b) files changed, (c) remaining Critical/High items, (d) access needed to complete untested areas, and (e) recommended pre-production security actions.

Do not state that the system is "secure," "fully compliant," or "OWASP compliant" based only on this review.
```

## P6: Independent code review (run in a fresh session, never the builder's session)

```
You are an independent reviewer. You did not write this code. Review pull request <number> / branch <name> against AGENTS.md, SECURITY.md and master document sections <list>.
Check and report on:
1. Scope: any file outside the ticket's allowed list.
2. Architecture: module boundaries, hexagonal layering, provider fields leaking outside connectors.
3. Correctness: state machine transitions, idempotency, integer money, balanced ledger postings, outbox events in the same transaction.
4. Security: tenant isolation and RLS, authorization policies, input validation, secrets, logging of sensitive data, fail-closed behavior.
5. Tests: do they actually prove the acceptance criteria, including cross-tenant and failure cases? Are any tests weakened?
6. API: OpenAPI updated and backward compatible.
Output a table: ID | Severity (Blocker/Major/Minor) | File:line | Issue | Required fix. Then a verdict: Approve for human review / Changes required. Do not fix the code yourself.
```

## P7: Database migration or schema change

```
Ticket <AR-number> requires a schema change: <describe>.
Produce: the migration (backward compatible, expand then contract), RLS policies for any new tenant table, role grants (ledger tables INSERT/SELECT only), indexes, a data backfill plan if needed, and a written rollback path.
Explain the effect on running code during a blue-green deploy.
Flag for human review if it touches money, identity, permissions or RLS.
```

## P8: Bug fix or incident

```
Bug / incident: <description, error messages, correlation IDs; no secrets or card data>.
1. Reproduce with a failing test first.
2. Find the root cause and explain it.
3. Fix with the smallest change; show the test now passes.
4. Check for the same bug elsewhere.
5. If money, ledger or security is affected, stop after step 2 and report to Mohit before changing code.
```

## P9: ADR and documentation

```
Draft ADR-<number> for: <decision>.
Sections: Context, Options considered (at least two), Decision, Consequences, Security and PCI impact, Rollback or exit path.
Keep it under one page. Mark it Proposed; only a human can mark it Accepted.
```

## P10: UI screen from the prototype

```
Build the <screen name> screen described in master document Section 12 and the AmpliRail Platform Screens V3 prototype (38 screens). Use the ticket row in docs/BACKLOG.md.
Rules: data only from existing AmpliRail API endpoints (never direct DB access from the UI), permission checks mirrored from the API, AmpliPay colors #1A5CFF and #0B1F4B, sentence case, no em-dashes, WCAG 2.2 AA, Sandbox or Live badge always visible, placeholders in brackets for unknown content.
Card fields must be processor-hosted fields only.
Include feature tests for access by each role that should and should not see the screen.
Every page includes the footer with Terms of service, Privacy policy, Security and System status.
```

## P11: Risk rule, limit or blocking feature

```
Implement <velocity limit / block rule / reserve / descriptor> for ticket <AR-number> per master document Section 10.1.
- Evaluate rules before routing, in a deterministic order, and record which rule matched on the attempt.
- Changes to a merchant's rules go through the Approvals service; never apply them directly.
- Emit limit.hit or block_rule.matched events and audit every change with before and after values.
- Tests: rule hit, rule miss, rule expired, cross-tenant rule cannot apply, approval required.
- For US state rules on gaming merchants, depend on the GeolocationProvider interface; do not use IP geolocation as the only signal.
```

## P12: Regulated or partner-dependent feature

```
Ticket <AR-number> is a regulated or partner-dependent feature (<wallet / card issuing / gift cards / stablecoins / dynamic descriptors>).
- Build it behind a feature flag named <flag> that defaults to off in every environment except local and sandbox.
- Put the partner behind a connector interface with a simulator implementation. Do not call a real partner.
- Never store crypto private keys, full card numbers or full bank numbers.
- List in the PR every legal or partner assumption you made so counsel can review.
```

## P13: PCI control and evidence

```
Ticket <AR-number> implements PCI DSS v4.0.1 requirement <number> per master document Section 14.5.
- Build the control so it produces evidence automatically (a stored report, log entry or record with date, result and owner).
- Show the evidence on the PCI compliance screen and add the recurring task to the evidence calendar if it repeats.
- Never weaken an existing control to make a test pass. If the control cannot be automated, write the manual procedure in docs/policies/ and say so in the PR.
- In the PR, state which PCI requirement and testing procedure the change supports, and what a QSA should look at.
```

## P14: Fees, currency or payout feature

```
Ticket <AR-number> touches fees, currencies or payouts (master document Sections 9.2 to 9.4).
- Fees: implement as data (fee types, schedules, rules), never as hard-coded amounts. Every assessment must reference its rule, schedule version and signed agreement, and post to the ledger.
- Currencies: use integer minor units with the currency's own decimals. Never add amounts in different currencies. Conversions only through an FxQuote within its expiry, storing provider, mid-rate, markup and client rate.
- Payouts: verify and sanctions-screen payout accounts first; dual control above threshold; Model B code stays behind its feature flag.
- Tests: JPY zero-decimal case, cross-currency refund under both refund policies, expired quote rejected, unsigned fee schedule not applied, ledger balanced in every currency.
```

## P15: Card-present, POS or vertical feature

```
Ticket <AR-number> touches card-present, POS, member charge or hotel folio (master document Sections 7.8 and 9.8).
- Card-present: pass the device's EMV or contactless data, entry mode, terminal capability and cardholder verification method to the connector unchanged. Never key or convert to e-commerce.
- Keyed in-store sales require AVS ZIP and CVV and must be flagged as keyed.
- Lodging and restaurants: estimated and incremental authorizations, tip adjust and delayed charges must follow card brand rules; record the original authorization reference.
- Member charge: store signature image reference and terminal ID with the charge; post to the house account in the ledger.
- Tests: chip, tap, keyed, tip adjust, incremental authorization, checkout capture, offline queue replay without duplicates.
```
