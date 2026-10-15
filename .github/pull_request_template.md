## Ticket
AR-___

## What changed
Short description.

## Files changed
List, and confirm they are all inside the ticket's allowed scope: yes / no

## Tests and checks run (paste summaries)
- composer test:
- composer stan:
- composer arch:
- composer contract:
- composer audit / npm audit:
- gitleaks:

## Security checklist
- [ ] No PAN, CVV, secrets or production data in code, fixtures, logs or this PR
- [ ] Tenant isolation tested for new endpoints or queries
- [ ] Authorization via Policy for every new action
- [ ] Money as integer minor units; ledger append-only respected
- [ ] Idempotency on new mutating endpoints
- [ ] Events via outbox for new state changes
- [ ] OpenAPI updated (or no API change)

## Reviews
- Independent review (P6) report: attached / link
- Security review (P5) report if security-relevant: attached / not required

## Rollback plan

## Human approval
Mohit: [ ]   Second reviewer (payments, identity, security, infra): [ ]
