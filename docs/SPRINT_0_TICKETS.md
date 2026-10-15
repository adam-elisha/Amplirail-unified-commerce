# Sprint 0 tickets (weeks 1 to 2)

| Ticket | Title | Acceptance criteria (summary) | Security-relevant |
| --- | --- | --- | --- |
| AR-001 | Company-owned GitHub org and repo protections | Org owned by company entity; Adam is org owner; main protected; required reviews and status checks; secret scanning on | Yes |
| AR-002 | Audit current CRM for card data | Report of every table, log and file that may hold PAN, CVV or bank data; purge plan for anything found; no data copied out | Yes |
| AR-003 | Starter pack in repo | AGENTS.md, CLAUDE.md, SECURITY.md, master document, prompt library, task and PR templates committed | No |
| AR-004 | Laravel 12 skeleton and module layout | Modules folder per Section 4.4; strict_types; PHPStan level 8 passes; Pest runs | No |
| AR-005 | CI pipeline | GitHub Actions running test, stan, arch, contract, audit, gitleaks, Semgrep, OpenAPI diff; PR blocked on failure | Yes |
| AR-006 | Terraform skeleton | Separate AWS accounts for sandbox, staging, production, backups; private networking; RDS PostgreSQL with PITR; KMS; Secrets Manager; no resources public | Yes |
| AR-007 | Tenancy module | org_nodes with materialized path; TenantContext middleware; RLS policies; queries without context fail; cross-tenant tests | Yes |
| AR-008 | Identity module | Users, memberships, roles, permissions from Section 5.3; Argon2id; emailed 8-digit one-time code for merchants and authenticator or security key for admins (Section 5.1.1, AR-081); 15-minute admin idle timeout; lockout; session rules; sign-in event log | Yes |
| AR-009 | Audit and outbox | Immutable audit table; outbox_events; Redis Streams relay; idempotent consumer base class; event envelope per Section 11 | Yes |
| AR-010 | Canonical schemas and OpenAPI v1 skeleton | Merchant, MerchantAccount, Transaction, PaymentAttempt, Refund, Event schemas; error shape; idempotency header | No |
| AR-011 | Connector contracts and ContractTests suite | Interfaces from Section 7; ProviderResult; capability manifest; shared contract test suite | No |
| AR-012 | Simulator connector | All scenarios in Section 7.1 deterministic; passes ContractTests | No |
| AR-013 | Backup policy and first restore test | AWS Backup plan with backup lock (compliance mode) in the backup account; restore test documented with timings | Yes |
| AR-014 | PCI data-flow document | docs/PCI_DATA_FLOW.md showing every entry point for payment data and that none reaches AmpliRail servers | Yes |
| AR-015 | Elavon SE meeting package | Diagram, use-case matrix, stack summary, PCI sketch, backlog, pilot plan (Elavon brief Section 7) | No |
