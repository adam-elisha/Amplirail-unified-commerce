# ADR-0004: RoboRiskAnalysis deployment boundary and sandbox provisioning state

- Status: **Proposed** (becomes Accepted only after the Lead eDataPay PHP Developer and Adam Elisha, Platform Owner, approve)
- Date: October 5, 2026 · Ticket: AR-174 · Supersedes: conflicting wording in Master V3.7 Section 6/10.2 and 4-Day design Section 4

## Context

Two V3.7 documents disagreed: (1) the master document (D18, Section 10.2) defines RoboRiskAnalysis (RRA) as a separate
product outside card-data scope, while the 4-Day Activation design described it as a Laravel module inside AmpliRail;
(2) the master state machine provisioned the sandbox at `approved`, the 4-Day model at application creation (Day 0).

## Decision 1: RoboRiskAnalysis is a separate Laravel/PHP product

RRA is built in Laravel/PHP but deployed as its own product: own repository (`roboriskanalysis`), own AWS account, network,
database and keys. It never receives PAN, CVV or full bank numbers. AmpliRail integrates **only** through the
`Connectors\Risk\RiskDecision` interface and consumes `risk.*` events. AmpliRail contains the interface, DTOs, a simulator
adapter and their tests, and nothing of RRA's scoring logic, tables or credentials.

Options considered: (a) module inside AmpliRail (rejected: brings RRA's vendors, AI tooling and evidence store into the
card-data environment and the QSA's scope; couples release cycles); (b) separate product behind a connector (chosen).

Consequences: an RRA outage cannot stop payments; underwriting falls back to the human decision path and the pre-submission
gate fails closed (an authorized override with a reason is recorded). Recommendations are advisory: an authorized human
records the decision (`risk.decide` + step-up), and controls change only when that human applies them.

PCI and security impact: keeps RRA outside the cardholder-data environment and outside AmpliRail's QSA scope (the point of
this ADR). Data sent: canonical, tokenized merchant data and document IDs only.

## Decision 2: sandbox is provisioned at `submitted` (Day 0)

On CRM handoff the merchant enters `submitted` and, in the same database transaction, receives a simulator
MerchantAccount, a test MID, an `arpub_sbx_`/`arsec_sbx_` key pair and an `arwhk_sbx_` webhook secret
(`merchant.sandbox_provisioned`). Production credentials remain separately controlled and are issued only at the
dual-controlled go-live.

Options considered: (a) at `approved` (rejected: integration work waits for underwriting and breaks the 4-day target);
(b) at `submitted` (chosen). Sandbox is simulator-only, so no financial exposure exists before approval.

Master state machine wording to apply once Accepted: "On submitted, the system automatically creates a sandbox
MerchantAccount on the simulator, a test MID, a sandbox API key pair and a webhook secret."

## Rollback

Revert the one-line edits to the master document Sections 6 and 10.2 and the 4-Day document Section 4; mark this ADR
Superseded. Code impact of a rollback for Decision 2: move the `SandboxProvisioner` call from `HandoffService` to the
`risk.approved` transition.
