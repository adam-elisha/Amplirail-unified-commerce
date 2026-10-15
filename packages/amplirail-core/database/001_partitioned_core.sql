-- AmpliRail core tables for high volume (Section 4.5). PostgreSQL 16.
-- Monthly range partitions; a scheduled job creates next month's partitions in advance (AR-161).
-- Money: bigint minor units + currency (rule 3). Row-level security per tenant (rule 7).

CREATE TABLE transactions (
  id               uuid        NOT NULL,
  tenant_id        uuid        NOT NULL,
  merchant_id      uuid        NOT NULL,
  merchant_account_id uuid     NOT NULL,          -- MID record
  idempotency_key  text        NOT NULL,
  kind             text        NOT NULL CHECK (kind IN ('sale','authorize','capture','refund','void')),
  status           text        NOT NULL,
  amount_minor     bigint      NOT NULL,
  currency         char(3)     NOT NULL,
  connector        text        NOT NULL,          -- simulator | nmi_bridge | gattera_bridge | ...
  provider_txn_id  text,
  card_brand       text,
  card_last4       char(4),
  card_type        text,                          -- credit | debit | prepaid
  entry_mode       text,                          -- chip | contactless | keyed | ecommerce
  initiator        text        NOT NULL DEFAULT 'customer',
  created_at       timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (id, created_at),
  UNIQUE (tenant_id, idempotency_key, created_at)
) PARTITION BY RANGE (created_at);

CREATE TABLE ledger_postings (
  id            bigint GENERATED ALWAYS AS IDENTITY,
  tenant_id     uuid        NOT NULL,
  journal_id    uuid        NOT NULL,             -- all postings of one journal sum to zero per currency
  account       text        NOT NULL,             -- e.g. merchant_balance:<id>, fee_revenue, reserve:<id>
  amount_minor  bigint      NOT NULL,             -- debit positive, credit negative
  currency      char(3)     NOT NULL,
  source_type   text        NOT NULL,
  source_id     uuid        NOT NULL,
  created_at    timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);
-- Append-only (rule 4): no UPDATE or DELETE for the application role.
REVOKE UPDATE, DELETE ON ledger_postings FROM amplirail_app;

CREATE TABLE outbox_events (
  id            bigint GENERATED ALWAYS AS IDENTITY,
  tenant_id     uuid        NOT NULL,
  event_type    text        NOT NULL,
  payload       jsonb       NOT NULL,
  published_at  timestamptz,
  created_at    timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);

-- Example partitions (job creates these monthly)
CREATE TABLE transactions_2026_10 PARTITION OF transactions FOR VALUES FROM ('2026-10-01') TO ('2026-11-01');
CREATE TABLE ledger_postings_2026_10 PARTITION OF ledger_postings FOR VALUES FROM ('2026-10-01') TO ('2026-11-01');
CREATE TABLE outbox_events_2026_10 PARTITION OF outbox_events FOR VALUES FROM ('2026-10-01') TO ('2026-11-01');

CREATE INDEX ON transactions (tenant_id, merchant_id, created_at DESC);
CREATE INDEX ON transactions (connector, provider_txn_id);
CREATE INDEX ON ledger_postings (tenant_id, account, created_at);
CREATE INDEX ON outbox_events (published_at) WHERE published_at IS NULL;

ALTER TABLE transactions ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON transactions USING (tenant_id = current_setting('app.tenant_id', false)::uuid);
ALTER TABLE ledger_postings ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON ledger_postings USING (tenant_id = current_setting('app.tenant_id', false)::uuid);
