# PCI data flow (AR-014)

Review at every connector change (SECURITY.md). Status: launch slice, October 5, 2026.

## Principle

Card data (PAN, expiry, CVV) never reaches AmpliRail servers, databases, logs or queues. It is entered only into
processor-hosted fields (NMI Collect.js iframes) or certified P2PE devices (later). AmpliRail receives a **token** and
display data (brand, last 4, type).

## Entry points

| Entry point | What the browser/caller sends to AmpliRail | Controls |
| --- | --- | --- |
| Pay page `pay.amplirail.com/l/{token}` (live) | Collect.js token + brand + last 4 | Card fields are processor iframes; CSP allows scripts only from self (nonce) and the configured Collect.js origin; `frame-ancestors 'none'`; any PAN-like value posted is rejected (Luhn scan). |
| Pay page (sandbox) | Simulator token (`tok_sim_approved`) | Simulator only; no card fields exist. |
| API `POST /v1/transactions` | `payment_token` from hosted fields | Request scanned for PAN (13-19 digits passing Luhn, spaces/dashes allowed) and rejected; core `PaymentRequest` refuses card-number-like tokens a second time. |
| Virtual terminal | Sandbox: simulator token. Live: hosted fields (to be enabled with NMI access) | Same scanning. |
| CRM handoff `POST /v1/merchants/handoff` | Merchant application, bank **token reference** | Fields named `account_number`, `routing_number`, `iban`, `card_number`, `pan`, `cvv`, `ssn` are rejected; PAN scan over the whole payload; EIN, owners and contacts field-encrypted. |
| KYB documents | Business evidence files (not card data) | Encrypted at rest (app key + S3 SSE-KMS), random names, signed 5-minute downloads, audited. Merchants are told never to upload card images; a PAN scanner over documents is a follow-up. |

## What AmpliRail stores per transaction

Token fingerprint (SHA-256 of the token, for velocity), brand, last 4, card type, provider transaction ID, auth code,
response code. **Never** PAN, expiry or CVV.

## Out of scope by design

RoboRiskAnalysis (separate account, ADR-0004) receives canonical merchant data and document IDs only. Outbound webhooks
carry transaction IDs and amounts only. Logs pass through a masking processor (`app/Support/Logging/MaskSensitiveData.php`)
and the log scan in `docs/ACCEPTANCE.md` is part of acceptance.

```
Customer browser --(card data)--> NMI Collect.js iframe --(token)--> AmpliRail pay page --> AmpliRail API --> NMI (token)
                                                                         |
                                       PostgreSQL: token fingerprint, brand, last4  (no PAN/CVV anywhere)
```
