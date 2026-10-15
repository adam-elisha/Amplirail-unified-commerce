# CLAUDE.md

Claude Code: follow AGENTS.md in this repository root. It is mandatory and applies to every session.

Also load, in this order: docs/MASTER_DEVELOPMENT_DOCUMENT_V3.md, the V3.7.1 controlling changes in
docs/source/ (Developer Handoff, Sprint 0.5 tickets, 4-Day Activation design), SECURITY.md, docs/IMPLEMENTATION_STATUS.md,
and the ticket you are given.

Useful commands:
- composer test (core 43 tests + Pest on SQLite)
- composer test:pgsql (feature + PostgreSQL controls; needs a non-superuser owner role, see phpunit.pgsql.xml)
- composer stan (PHPStan level 8)
- composer arch (architecture tests: module boundaries, strict types)
- composer contract (connector contract tests)
- composer audit
- gitleaks detect --no-banner

Module boundaries: a module may use another module only through its Contracts namespace (enforced by composer arch).
State changes go through MerchantLifecycle / the transaction state machine and write the outbox in the same transaction.

Prompt library: docs/prompts/PROMPT_LIBRARY.md
