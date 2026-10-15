<?php

declare(strict_types=1);

use App\Support\Database\Partitions;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * PostgreSQL-only controls (SECURITY.md "Database"). These use raw SQL on purpose: they prove the DATABASE enforces
 * tenant isolation and immutability even if application code were bypassed. Run with: composer test:pgsql
 */

beforeEach(function () {
    if (! $this->isPostgres()) {
        $this->markTestSkipped('PostgreSQL only');
    }
    $this->seedPlatform();
});

/** Each failing statement runs in its own savepoint so one expected error does not abort the test transaction. */
function dbFails(callable $sql): callable
{
    return fn () => DB::transaction($sql);
}

function setTenant(string $id): void
{
    DB::select("select set_config('app.tenant_id', ?, false)", [$id]);
}

it('forces row-level security on every tenant table', function () {
    $rows = DB::select("select relname from pg_class where relkind in ('r','p') and relrowsecurity and relforcerowsecurity and relnamespace = 'public'::regnamespace");
    $tables = array_column($rows, 'relname');
    foreach (['merchants', 'transactions', 'ledger_postings', 'outbox_events', 'approvals', 'merchant_documents', 'payout_statements', 'webhook_endpoints', 'idempotency_keys', 'audit_log'] as $t) {
        expect($tables)->toContain($t);
    }
});

it('hides other tenants\' rows from raw SQL and fails closed with no tenant context', function () {
    $m = $this->handoff();
    setTenant(self::TENANT);
    expect(DB::selectOne('select count(*) as n from merchants where id = ?', [$m['id']])->n)->toBe(1);
    setTenant(self::OTHER_TENANT);
    expect(DB::selectOne('select count(*) as n from merchants')->n)->toBe(0)
        ->and(DB::selectOne('select count(*) as n from outbox_events')->n)->toBe(0)
        ->and(DB::selectOne('select count(*) as n from merchant_accounts')->n)->toBe(0);
    setTenant('');
    expect(DB::selectOne('select count(*) as n from merchants')->n)->toBe(0);
});

it('rejects writes into another tenant (WITH CHECK)', function () {
    setTenant(self::OTHER_TENANT);
    expect(fn () => DB::insert("insert into approvals (id, tenant_id, type, subject_type, subject_id, status, payload, requested_by, requested_at, created_at, updated_at)
        values ('apr_x', ?, 'go_live', 'merchant', 'mer_x', 'pending', '{}', 'u', now(), now(), now())", [self::TENANT]))
        ->toThrow(QueryException::class, 'row-level security');
});

it('keeps the ledger, audit log and transition history append-only even for the owner role', function () {
    $m = $this->handoff();
    $secret = $this->sandboxSecret($m['id']);
    $this->withToken($secret)->withHeader('Idempotency-Key', 'pg-1')
        ->postJson('/v1/transactions', ['amount' => ['amount_minor' => 1000, 'currency' => 'USD'], 'payment_token' => 'tok_sim_approved', 'order_reference' => 'PG1'])->assertCreated();
    setTenant(self::TENANT);
    expect(dbFails(fn () => DB::update('update ledger_postings set amount_minor = 0')))->toThrow(QueryException::class, 'append-only');
    expect(dbFails(fn () => DB::delete('delete from ledger_postings')))->toThrow(QueryException::class, 'append-only');
    expect(dbFails(fn () => DB::update("update audit_log set action = 'x'")))->toThrow(QueryException::class, 'append-only');
    expect(dbFails(fn () => DB::delete('delete from merchant_state_transitions')))->toThrow(QueryException::class, 'append-only');
    expect(dbFails(fn () => DB::update("update merchants set crm_deal_id = 'D-hijack'")))->toThrow(QueryException::class, 'immutable');
    expect(dbFails(fn () => DB::delete('delete from merchants')))->toThrow(QueryException::class);
});

it('refuses changes to an issued payout statement', function () {
    $m = $this->handoff();
    setTenant(self::TENANT);
    DB::insert("insert into payout_statements (id, tenant_id, merchant_id, merchant_account_id, mid_label, period_start, period_end, currency, fee_schedule_id,
        fee_schedule_version, input, lines, gross_minor, fees_minor, reserve_minor, net_minor, cb_ratio_bps, status, created_at, updated_at)
        values ('ps_x', ?, ?, 'macc_x', 'TMID', '2026-10-01', '2026-10-07', 'USD', 'fs', 'v1', '{}', '[]', 1, 0, 0, 1, 0, 'issued', now(), now())", [self::TENANT, $m['id']]);
    expect(dbFails(fn () => DB::update("update payout_statements set net_minor = 2 where id = 'ps_x'")))->toThrow(QueryException::class, 'immutable');
});

it('partitions high-volume tables by month and routes rows into the current partition', function () {
    $m = $this->handoff();
    $secret = $this->sandboxSecret($m['id']);
    $txn = $this->withToken($secret)->withHeader('Idempotency-Key', 'pg-2')
        ->postJson('/v1/transactions', ['amount' => ['amount_minor' => 1000, 'currency' => 'USD'], 'payment_token' => 'tok_sim_approved', 'order_reference' => 'PG2'])->json();
    setTenant(self::TENANT);
    $partition = DB::selectOne('select tableoid::regclass::text as p from transactions where id = ?', [$txn['id']])->p;
    expect($partition)->toBe('transactions_'.now()->utc()->format('Y_m'));
    expect(Partitions::ensure(DB::connection(), 3))->toBe([]);      // already created by the migration: idempotent
    foreach (Partitions::TABLES as $t) {
        expect(DB::selectOne('select to_regclass(?) as r', ['public.'.$t.'_default'])->r)->not->toBeNull();
    }
});

it('lets the runtime role read and write through RLS but never edit the ledger (real login as that role)', function () {
    $password = (string) env('DB_APP_PASSWORD', '');
    if ($password === '') {
        $this->markTestSkipped('Set DB_APP_PASSWORD to run the live runtime-role check.');
    }
    config(['database.connections.pgsql_app_check' => array_merge(config('database.connections.pgsql'),
        ['username' => config('amplirail.runtime_db_role'), 'password' => $password])]);
    $app = DB::connection('pgsql_app_check');
    $app->select("select set_config('app.tenant_id', ?, false)", [self::TENANT]);
    expect($app->selectOne('select count(*) as n from merchants')->n)->toBeInt();       // has SELECT
    expect($app->selectOne('select count(*) as n from ledger_postings')->n)->toBeInt();
    expect(fn () => $app->update('update ledger_postings set amount_minor = 0'))->toThrow(QueryException::class, 'permission denied');
    expect(fn () => $app->statement('create table should_fail (id int)'))->toThrow(QueryException::class, 'permission denied');
    $app->disconnect();
});

it('grants the runtime role only what it needs', function () {
    $priv = fn (string $table, string $p) => (bool) DB::selectOne('select has_table_privilege(?, ?, ?) as ok', [config('amplirail.runtime_db_role'), $table, $p])->ok;
    expect($priv('ledger_postings', 'INSERT'))->toBeTrue()
        ->and($priv('ledger_postings', 'UPDATE'))->toBeFalse()
        ->and($priv('ledger_postings', 'DELETE'))->toBeFalse()
        ->and($priv('audit_log', 'UPDATE'))->toBeFalse()
        ->and($priv('merchants', 'DELETE'))->toBeFalse()
        ->and($priv('merchants', 'UPDATE'))->toBeTrue()
        ->and($priv('transactions', 'UPDATE'))->toBeTrue();
    expect((bool) DB::selectOne('select rolsuper from pg_roles where rolname = ?', [config('amplirail.runtime_db_role')])->rolsuper)->toBeFalse();
});
