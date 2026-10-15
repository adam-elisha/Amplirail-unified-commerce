<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL-only database controls (SECURITY.md "Database", AGENTS.md rules 5 and 8):
 *  - Row-level security on every tenant table, FORCED so even the table owner obeys it. Policy key: app.tenant_id,
 *    set per request/job by TenantManager. No setting means no rows (fail closed).
 *  - Append-only ledger_postings, audit_log and auth_events (trigger blocks UPDATE/DELETE for every role).
 *  - crm_deal_id and the original agent are immutable once set; issued payout statements are immutable.
 *  - Least privilege for the runtime role amplirail_app (if it exists): DML only, no UPDATE/DELETE on append-only tables.
 *    Migrations and partition maintenance run as the owner role.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tenantTables = [
        'merchants', 'merchant_state_transitions', 'risk_cases', 'kyb_checks', 'document_requirements', 'merchant_documents',
        'document_requests', 'eligibility_rule_sets', 'eligibility_results', 'approvals', 'validation_runs', 'go_live_evaluations',
        'merchant_accounts', 'transactions', 'transaction_attempts', 'refunds', 'ledger_postings', 'outbox_events',
        'idempotency_keys', 'payment_links', 'webhook_endpoints', 'webhook_deliveries', 'fee_schedules', 'fee_agreements',
        'payout_statements',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tenantTables as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON $table USING (tenant_id = current_setting('app.tenant_id', true)) WITH CHECK (tenant_id = current_setting('app.tenant_id', true))");
        }
        // Audit rows without a tenant are platform-level and only visible without a tenant context.
        DB::statement('ALTER TABLE audit_log ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_log FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_isolation ON audit_log USING (tenant_id IS NOT DISTINCT FROM NULLIF(current_setting('app.tenant_id', true), '')) WITH CHECK (tenant_id IS NOT DISTINCT FROM NULLIF(current_setting('app.tenant_id', true), ''))");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION amplirail_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION '% is append-only (% blocked)', TG_TABLE_NAME, TG_OP USING ERRCODE = 'insufficient_privilege';
END $$;

CREATE TRIGGER ledger_postings_append_only BEFORE UPDATE OR DELETE ON ledger_postings FOR EACH ROW EXECUTE FUNCTION amplirail_append_only();
CREATE TRIGGER audit_log_append_only BEFORE UPDATE OR DELETE ON audit_log FOR EACH ROW EXECUTE FUNCTION amplirail_append_only();
CREATE TRIGGER auth_events_append_only BEFORE UPDATE OR DELETE ON auth_events FOR EACH ROW EXECUTE FUNCTION amplirail_append_only();
CREATE TRIGGER merchant_state_transitions_append_only BEFORE UPDATE OR DELETE ON merchant_state_transitions FOR EACH ROW EXECUTE FUNCTION amplirail_append_only();

CREATE OR REPLACE FUNCTION amplirail_merchant_immutables() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.crm_deal_id IS NOT NULL AND NEW.crm_deal_id IS DISTINCT FROM OLD.crm_deal_id THEN
    RAISE EXCEPTION 'crm_deal_id is immutable' USING ERRCODE = 'insufficient_privilege';
  END IF;
  IF OLD.agent IS NOT NULL AND NEW.agent IS DISTINCT FROM OLD.agent THEN
    RAISE EXCEPTION 'original agent is locked' USING ERRCODE = 'insufficient_privilege';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER merchants_immutables BEFORE UPDATE ON merchants FOR EACH ROW EXECUTE FUNCTION amplirail_merchant_immutables();
CREATE TRIGGER merchants_no_delete BEFORE DELETE ON merchants FOR EACH ROW EXECUTE FUNCTION amplirail_append_only();

CREATE OR REPLACE FUNCTION amplirail_issued_statement_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.status = 'issued' THEN
    RAISE EXCEPTION 'issued payout statements are immutable; use an adjustment line on the next statement' USING ERRCODE = 'insufficient_privilege';
  END IF;
  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER payout_statements_immutable BEFORE UPDATE OR DELETE ON payout_statements FOR EACH ROW EXECUTE FUNCTION amplirail_issued_statement_immutable();
SQL);

        $this->grantRuntimeRole();
    }

    /**
     * Least-privilege grants for the runtime role (DB_APP_ROLE, default = DB_USERNAME of the app connection).
     * Fails loudly instead of skipping: an app user without grants cannot work, and an app that runs as the owner
     * would bypass the grant restrictions.
     */
    private function grantRuntimeRole(): void
    {
        $role = (string) config('amplirail.runtime_db_role');
        if (! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role)) {
            throw new \RuntimeException("DB_APP_ROLE [$role] is not a valid PostgreSQL role name.");
        }
        if (DB::selectOne('select 1 as ok from pg_roles where rolname = ?', [$role]) === null) {
            throw new \RuntimeException("Runtime role [$role] does not exist. Create it first (deploy/postgres/01-roles.sql) or set DB_APP_ROLE.");
        }
        if (DB::selectOne('select current_user as u')->u === $role) {
            throw new \RuntimeException("Migrations are running as the runtime role [$role]. Run them with the owner role: php artisan migrate --database=pgsql_owner.");
        }
        $r = '"'.$role.'"';
        foreach ([
            "GRANT USAGE ON SCHEMA public TO $r",
            "GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO $r",
            "GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO $r",
            "REVOKE UPDATE, DELETE, TRUNCATE ON ledger_postings, audit_log, auth_events, merchant_state_transitions FROM $r",
            "REVOKE DELETE, TRUNCATE ON merchants, payout_statements FROM $r",
            "REVOKE ALL ON migrations FROM $r",
            "ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO $r",
            "ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO $r",
        ] as $sql) {
            DB::statement($sql);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        foreach (array_merge($this->tenantTables, ['audit_log']) as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON $table");
            DB::statement("ALTER TABLE $table NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table DISABLE ROW LEVEL SECURITY");
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS ledger_postings_append_only ON ledger_postings;
DROP TRIGGER IF EXISTS audit_log_append_only ON audit_log;
DROP TRIGGER IF EXISTS auth_events_append_only ON auth_events;
DROP TRIGGER IF EXISTS merchant_state_transitions_append_only ON merchant_state_transitions;
DROP TRIGGER IF EXISTS merchants_immutables ON merchants;
DROP TRIGGER IF EXISTS merchants_no_delete ON merchants;
DROP TRIGGER IF EXISTS payout_statements_immutable ON payout_statements;
DROP FUNCTION IF EXISTS amplirail_append_only();
DROP FUNCTION IF EXISTS amplirail_merchant_immutables();
DROP FUNCTION IF EXISTS amplirail_issued_statement_immutable();
SQL);
    }
};
