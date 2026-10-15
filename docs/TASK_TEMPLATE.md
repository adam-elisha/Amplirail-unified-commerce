# Ticket AR-___: <title>

**Sprint:** <n>   **Owner:** Mohit   **Agent role:** Builder / Test / Architect / Docs
**Plan first:** yes / no   **Security-relevant:** yes / no (auth, tenancy, payments, ledger, secrets, infra, DB permissions)

## Goal
One or two sentences on the outcome.

## Context
Links to master document sections, ADRs and related tickets.

## Scope: files allowed to change
- modules/<Module>/...
- tests/...
- openapi/...

## Out of scope
What must not be touched.

## Acceptance criteria
1. ...
2. ...

## Tests required
- Unit: ...
- Feature / API: ...
- Contract / abuse case / tenant isolation: ...

## Security considerations
Data classes touched, permissions, failure mode (fail closed?), logging redaction.

## Rollback
How to revert safely (migration down path, feature flag).
