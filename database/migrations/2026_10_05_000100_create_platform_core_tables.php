<?php

declare(strict_types=1);

use App\Support\Database\Partitions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AR-009 audit and outbox, idempotency, and the AR-161 partitioned high-volume tables (from core
 * database/001_partitioned_core.sql, adapted: prefixed text IDs instead of uuid, and idempotency uniqueness moved to
 * idempotency_keys because a partitioned UNIQUE must include created_at and therefore cannot enforce it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 40)->nullable()->index();
            $table->string('action', 80);
            $table->string('actor_type', 20);
            $table->string('actor_id', 60);
            $table->string('subject_type', 40);
            $table->string('subject_id', 60);
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->string('correlation_id', 80)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('feature_flags', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->boolean('enabled');
            $table->string('updated_by', 60)->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('processed_events', function (Blueprint $table) {
            $table->string('consumer', 60);
            $table->string('event_id', 40);
            $table->timestamp('processed_at');
            $table->primary(['consumer', 'event_id']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 40);
            $table->string('scope', 60);               // e.g. merchants.handoff, transactions.create:<merchant>
            $table->string('idem_key', 128);
            $table->string('request_hash', 64);
            $table->string('status', 20);              // in_flight | completed
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'scope', 'idem_key']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->createPartitionedPostgres();
        } else {
            $this->createPlain();
        }
    }

    private function createPartitionedPostgres(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE transactions (
  id                  varchar(40)  NOT NULL,
  tenant_id           varchar(40)  NOT NULL,
  merchant_id         varchar(40)  NOT NULL,
  merchant_account_id varchar(40),
  environment         varchar(10)  NOT NULL,
  idempotency_key     varchar(128) NOT NULL,
  kind                varchar(20)  NOT NULL CHECK (kind IN ('sale','authorize')),
  status              varchar(20)  NOT NULL,
  state               varchar(30)  NOT NULL,
  amount_minor        bigint       NOT NULL,
  currency            char(3)      NOT NULL,
  captured_minor      bigint       NOT NULL DEFAULT 0,
  refunded_minor      bigint       NOT NULL DEFAULT 0,
  connector           varchar(40),
  provider_txn_id     varchar(100),
  auth_code           varchar(20),
  response_code       varchar(20),
  decline_category    varchar(40),
  reason              varchar(255),
  soft_decline        boolean      NOT NULL DEFAULT false,
  card_brand          varchar(20),
  card_last4          char(4),
  card_type           varchar(10),
  entry_mode          varchar(20),
  token_fingerprint   varchar(64),
  order_reference     varchar(255) NOT NULL,
  initiator           varchar(10)  NOT NULL DEFAULT 'customer',
  channel             varchar(30)  NOT NULL DEFAULT 'api',
  payment_link_id     varchar(40),
  correlation_id      varchar(80),
  created_at          timestamp(0) NOT NULL,
  updated_at          timestamp(0),
  PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);
CREATE TABLE transactions_default PARTITION OF transactions DEFAULT;
CREATE INDEX transactions_tenant_merchant_created ON transactions (tenant_id, merchant_id, created_at DESC);
CREATE INDEX transactions_tenant_id ON transactions (tenant_id, id);
CREATE INDEX transactions_provider ON transactions (connector, provider_txn_id);

CREATE TABLE transaction_attempts (
  id                  varchar(40)  NOT NULL,
  tenant_id           varchar(40)  NOT NULL,
  transaction_id      varchar(40)  NOT NULL,
  merchant_account_id varchar(40)  NOT NULL,
  connector           varchar(40)  NOT NULL,
  operation           varchar(20)  NOT NULL,
  outcome             varchar(20)  NOT NULL,
  response_code       varchar(40),
  message             varchar(255),
  retryable           boolean      NOT NULL DEFAULT false,
  soft_decline        boolean      NOT NULL DEFAULT false,
  latency_ms          integer,
  created_at          timestamp(0) NOT NULL,
  PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);
CREATE TABLE transaction_attempts_default PARTITION OF transaction_attempts DEFAULT;
CREATE INDEX transaction_attempts_txn ON transaction_attempts (tenant_id, transaction_id);

CREATE TABLE ledger_postings (
  id            bigint GENERATED ALWAYS AS IDENTITY,
  tenant_id     varchar(40)  NOT NULL,
  journal_id    varchar(40)  NOT NULL,
  account       varchar(120) NOT NULL,
  amount_minor  bigint       NOT NULL,
  currency      char(3)      NOT NULL,
  source_type   varchar(40)  NOT NULL,
  source_id     varchar(40)  NOT NULL,
  environment   varchar(10)  NOT NULL,
  created_at    timestamp(0) NOT NULL,
  PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);
CREATE TABLE ledger_postings_default PARTITION OF ledger_postings DEFAULT;
CREATE INDEX ledger_postings_account ON ledger_postings (tenant_id, account, created_at);
CREATE INDEX ledger_postings_journal ON ledger_postings (tenant_id, journal_id);
CREATE INDEX ledger_postings_source ON ledger_postings (tenant_id, source_id);

CREATE TABLE outbox_events (
  id             bigint GENERATED ALWAYS AS IDENTITY,
  event_id       varchar(40)  NOT NULL,
  tenant_id      varchar(40)  NOT NULL,
  type           varchar(80)  NOT NULL,
  version        smallint     NOT NULL DEFAULT 1,
  environment    varchar(10)  NOT NULL,
  org_path       varchar(500) NOT NULL,
  actor          jsonb        NOT NULL,
  correlation_id varchar(80)  NOT NULL,
  subject_type   varchar(40)  NOT NULL,
  subject_id     varchar(40)  NOT NULL,
  merchant_id    varchar(40),
  data           jsonb        NOT NULL,
  occurred_at    timestamp(0) NOT NULL,
  published_at   timestamp(0),
  attempts       integer      NOT NULL DEFAULT 0,
  created_at     timestamp(0) NOT NULL,
  PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);
CREATE TABLE outbox_events_default PARTITION OF outbox_events DEFAULT;
CREATE INDEX outbox_events_unpublished ON outbox_events (tenant_id, id) WHERE published_at IS NULL;
CREATE INDEX outbox_events_subject ON outbox_events (tenant_id, subject_id);
CREATE INDEX outbox_events_merchant ON outbox_events (tenant_id, merchant_id);
CREATE INDEX outbox_events_event_id ON outbox_events (event_id);
SQL);
        Partitions::ensure(DB::connection(), 3);
    }

    private function createPlain(): void
    {
        Schema::create('transactions', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('tenant_id', 40);
            $t->string('merchant_id', 40);
            $t->string('merchant_account_id', 40)->nullable();
            $t->string('environment', 10);
            $t->string('idempotency_key', 128);
            $t->string('kind', 20);
            $t->string('status', 20);
            $t->string('state', 30);
            $t->bigInteger('amount_minor');
            $t->char('currency', 3);
            $t->bigInteger('captured_minor')->default(0);
            $t->bigInteger('refunded_minor')->default(0);
            $t->string('connector', 40)->nullable();
            $t->string('provider_txn_id', 100)->nullable();
            $t->string('auth_code', 20)->nullable();
            $t->string('response_code', 20)->nullable();
            $t->string('decline_category', 40)->nullable();
            $t->string('reason')->nullable();
            $t->boolean('soft_decline')->default(false);
            $t->string('card_brand', 20)->nullable();
            $t->char('card_last4', 4)->nullable();
            $t->string('card_type', 10)->nullable();
            $t->string('entry_mode', 20)->nullable();
            $t->string('token_fingerprint', 64)->nullable();
            $t->string('order_reference');
            $t->string('initiator', 10)->default('customer');
            $t->string('channel', 30)->default('api');
            $t->string('payment_link_id', 40)->nullable();
            $t->string('correlation_id', 80)->nullable();
            $t->timestamp('created_at');
            $t->timestamp('updated_at')->nullable();
            $t->index(['tenant_id', 'merchant_id', 'created_at']);
        });
        Schema::create('transaction_attempts', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('tenant_id', 40);
            $t->string('transaction_id', 40);
            $t->string('merchant_account_id', 40);
            $t->string('connector', 40);
            $t->string('operation', 20);
            $t->string('outcome', 20);
            $t->string('response_code', 40)->nullable();
            $t->string('message')->nullable();
            $t->boolean('retryable')->default(false);
            $t->boolean('soft_decline')->default(false);
            $t->integer('latency_ms')->nullable();
            $t->timestamp('created_at');
            $t->index(['tenant_id', 'transaction_id']);
        });
        Schema::create('ledger_postings', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id', 40);
            $t->string('journal_id', 40);
            $t->string('account', 120);
            $t->bigInteger('amount_minor');
            $t->char('currency', 3);
            $t->string('source_type', 40);
            $t->string('source_id', 40);
            $t->string('environment', 10);
            $t->timestamp('created_at');
            $t->index(['tenant_id', 'account']);
            $t->index(['tenant_id', 'source_id']);
        });
        Schema::create('outbox_events', function (Blueprint $t) {
            $t->id();
            $t->string('event_id', 40)->index();
            $t->string('tenant_id', 40);
            $t->string('type', 80);
            $t->smallInteger('version')->default(1);
            $t->string('environment', 10);
            $t->string('org_path', 500);
            $t->jsonb('actor');
            $t->string('correlation_id', 80);
            $t->string('subject_type', 40);
            $t->string('subject_id', 40);
            $t->string('merchant_id', 40)->nullable();
            $t->jsonb('data');
            $t->timestamp('occurred_at');
            $t->timestamp('published_at')->nullable();
            $t->integer('attempts')->default(0);
            $t->timestamp('created_at');
            $t->index(['tenant_id', 'published_at']);
            $t->index(['tenant_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        foreach (['outbox_events', 'ledger_postings', 'transaction_attempts', 'transactions', 'idempotency_keys', 'processed_events', 'feature_flags', 'audit_log'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
