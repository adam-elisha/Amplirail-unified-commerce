# Acceptance checks

## Milestone #1 (master document Section 20, V3.7.1 Section 12)

Automated: `tests/Feature/Milestone1Test.php`, `PortalTest.php` ("full Milestone #1 timeline"), `StatementsAndWebhooksTest.php`
("no secrets or card data in the logs"). Run `composer test` and `composer test:pgsql`.

Manual run on a fresh environment (about 15 minutes, sandbox, no real data):

1. Sign in to the admin console (authenticator required). Add merchant, or call the CRM handoff with a service token.
   **Expect** state `submitted`, a `TMID-` sandbox MID, `arpub_sbx_` key, masked `arsec_sbx_` key, and events
   `application.created`, `merchant.submitted`, `merchant.sandbox_provisioned` on the Merchant 360 timeline.
2. As the merchant (portal, emailed code): Developers and API keys > Reveal once (copy the secret); add a webhook endpoint
   (an HTTPS URL you control, for example a request-bin) with `transaction.approved`.
3. `$100.00` simulator sale:
   ```bash
   curl -s https://HOST/v1/transactions -H "Authorization: Bearer arsec_sbx_..." -H "Idempotency-Key: m1-$(date +%s)" \
     -H "Content-Type: application/json" \
     -d '{"amount":{"amount_minor":10000,"currency":"USD"},"payment_token":"tok_sim_approved","order_reference":"M1"}'
   ```
   **Expect** `201`, `status: approved`, `state: captured`. Repeat with the same Idempotency-Key: same `id`,
   header `Idempotent-Replayed: true`.
4. **Expect** a signed webhook at your endpoint within seconds; verify `AmpliRail-Signature` with the secret from step 2
   (`t=<unix>,v1=hex(HMAC-SHA256(secret, t + "." + body))`).
5. Admin > Transactions > the transaction: attempts, two ledger postings that sum to zero, the event timeline, the
   delivery; press Replay and check the audit trail (Management) shows `webhook_delivery.replayed`.
6. Forced outcomes: amounts `10.02` (declined, do_not_honor, not rerouted), `10.51`, `10.59`, `10.65`, `10.99`
   (timeout, fails over), and `"simulator":"sim_outage"`.
7. Isolation: a second merchant's key gets `404` on the first merchant's transaction.

## SQL checks (run as the owner role with the tenant set)

```sql
select set_config('app.tenant_id', 'org_edatapay', false);

-- Ledger: every journal sums to zero per currency (expect no rows)
select journal_id, currency, sum(amount_minor) from ledger_postings group by 1, 2 having sum(amount_minor) <> 0;

-- No duplicate charges for one idempotency key (expect no rows)
select merchant_id, idempotency_key, count(*) from transactions group by 1, 2 having count(*) > 1;

-- Outbox backlog (expect 0 or a handful while the relay is running)
select count(*) from outbox_events where published_at is null;

-- Merchant payable equals captured sales minus refunds, per merchant (spot check)
select account, sum(amount_minor) from ledger_postings where account like 'merchant_payable:%' group by 1;
```

## Log scan (no secrets or card data)

```bash
grep -RInE '(^|[^0-9])[0-9]{13,19}([^0-9]|$)|arsec_(sbx|live)_[A-Za-z0-9]{8}|arwhk_|arsvt_[A-Za-z0-9]{8}|security_key=' storage/logs/ && echo "FOUND - incident" || echo "clean"
```

## Load test gate (AR-165, staging, simulator only)

`k6 run -e BASE=https://staging-host -e KEY=arsec_sbx_... packages/amplirail-core/loadtest/sale_200rps.js`
(the script appends `/v1/transactions`). Raise the test merchant's `max_per_token_per_hour` in its go-live configuration
first, or the velocity screen will (correctly) block the repeated simulator token. Pass: p95 < 150 ms added latency,
errors < 0.1 %, then the SQL checks above.
