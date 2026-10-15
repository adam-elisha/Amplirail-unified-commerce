# SECURITY.md: AmpliRail security requirements

Baseline: OWASP Top 10:2025, OWASP ASVS for deeper checks, PCI DSS for card data, Nacha for ACH. Full detail is in the master document, Sections 14 to 16.

## Card data boundary

- Card entry only through processor-hosted fields (NMI Collect.js; Elavon Lightbox or Hosted Payment Page once the Solution Engineer confirms) and certified P2PE / semi-integrated devices.
- AmpliRail stores token references, brand, last4, BIN where permitted and expiry. Nothing else.
- CVV is never stored anywhere.
- docs/PCI_DATA_FLOW.md must be updated for any change to how payment data enters or moves.

## Identity and access

- Merchant portal: email + password, then an emailed 8-digit one-time code (hash stored, 10 minutes, single use, 5 per hour).
- Admin console: authenticator app or security key required (no emailed codes); 15 minute idle timeout (PCI DSS 8.2.8). Super admin: security key and IP allowlist. Super admin: WebAuthn hardware key and IP allowlist.
- Passwords hashed with Argon2id. Lock after 5 failed attempts for 30 minutes.
- Step-up MFA and, where marked, a second approver for refunds above threshold, routing publish, risk decisions, payout approval, role changes and agent attribution overrides.
- Every sign-in event is logged (user, portal, IP, user agent, location, method, result).

## Application controls

- Tenant scope in code plus PostgreSQL RLS. Fail closed without context.
- Parameterized queries only. FormRequest validation on every input. Output encoding.
- Security headers: HSTS, CSP, X-Content-Type-Options, frame-ancestors, Referrer-Policy.
- Strict CORS allowlist. CSRF protection on all session routes.
- Webhooks in and out: HMAC-SHA256 with timestamp, 5-minute tolerance.
- Rate limits on login, reset, tokenize and authorize. Card-testing detection.
- File uploads: type and size checks, content sniffing, random names, private S3, no execution.

## Payment rails

- ACH and eCheck: store Nacha authorization evidence; account numbers field-encrypted; returns tracked.
- Stablecoins: provider holds custody; AmpliRail stores references only and never private keys.
- Blocking and velocity rules run before routing and are audited when matched.
- Terms of service and Privacy policy linked on every page.

## Secrets

- AWS Secrets Manager and KMS only. Never in git, .env in production, prompts, tickets or logs.
- Gitleaks in pre-commit and CI. An exposed secret is rotated immediately and treated as an incident.

## Database

- Private subnets, no public IP, TLS required, KMS encryption at rest.
- Separate roles per module; read-only reporting role; dedicated migration role.
- Ledger entries: INSERT and SELECT only.
- Field-level encryption for SSN, tax ID and bank account numbers.
- pgaudit with alerts on bulk export, schema change and off-hours admin sessions.
- No production data in development, tests or AI sessions.

## Backups and recovery

- Point-in-time recovery (35 days) on production PostgreSQL.
- Daily snapshots copied to a separate AWS backup account and region, locked with AWS Backup lock and S3 Object Lock (compliance mode).
- Monthly restore test with recorded results and ledger balance check.
- Targets: RPO 5 minutes, RTO 4 hours.
- Never Dropbox or Google Drive for backups or data exports.

## Logging

- Log auth, admin, money-moving and configuration events immutably with correlation IDs.
- Never log PAN, CVV, secrets, full bank account numbers or full SSNs.
- Retain security logs at least 12 months with 3 months immediately searchable.

## PCI DSS v4.0.1 (service provider) essentials

- Target: Level 1 Service Provider ROC and AOC by a QSA. Control map: master document Section 14.5.
- Payment pages (hosted page, virtual terminal, payment requests, any page with payment fields): Content Security Policy with nonces, Subresource Integrity, script registry with justification, tamper detection every 15 minutes (6.4.3, 11.6.1).
- PAN and CVV scanner runs daily over database, logs, files and tickets; any finding is an incident (3, 12.10.7).
- WAF on all public endpoints (6.4.2). SBOM in CI; critical patches within one month (6.3).
- Access reviews every 6 months (7.2.4). Logs reviewed daily by automation; alert when a security control stops (10.4.1.1, 10.7.2).
- Quarterly internal and ASV scans; yearly penetration test; segmentation test every 6 months (11.3, 11.4).
- Yearly secure-coding training for developers and security awareness with phishing for all staff (6.2.2, 12.6).
- RoboRiskAnalysis and stablecoin services stay in separate accounts outside the card-data scope.

## AI tools and vendors

- Approved AI tools and roles are listed in master document Section 18.1. Business or enterprise plans only; no training on our code.
- Open-weight models only when self-hosted on AmpliPay infrastructure.
- Keep the vendor list current for PCI DSS Requirement 12.8.

## Reporting a vulnerability or incident

Tell Mohit and Adam immediately. Do not discuss details in public channels or AI tools outside the approved project workspace.
