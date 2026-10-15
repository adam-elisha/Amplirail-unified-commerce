<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Disputes and chargebacks (master document 9.6). One row per network case. The timeline lives in the outbox
 * (dispute.* events); money moves only through ledger journals posted by DisputeService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $t) {
            $t->string('id', 40)->primary();                 // dsp_...
            $t->string('tenant_id', 40);
            $t->string('merchant_id', 40)->index();
            $t->string('merchant_account_id', 40)->nullable();
            $t->string('transaction_id', 40)->index();
            $t->string('environment', 10);
            $t->bigInteger('amount_minor');
            $t->char('currency', 3);
            $t->string('stage', 20)->default('chargeback');   // inquiry | chargeback | pre_arbitration | arbitration
            $t->string('reason_category', 40);               // fraud | not_received | not_as_described | duplicate | credit_not_processed | subscription_cancelled | other
            $t->string('reason_code', 20)->nullable();        // network reason code as received
            $t->string('network_case_id', 80);
            $t->string('status', 20)->default('needs_response'); // needs_response | under_review | won | lost | accepted
            $t->timestamp('due_at')->nullable()->index();
            $t->text('merchant_response')->nullable();
            $t->jsonb('evidence')->nullable();               // [{type, description, document_id?}]
            $t->text('resolution_note')->nullable();
            $t->string('opened_by', 60);
            $t->string('resolved_by', 60)->nullable();
            $t->timestamp('responded_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'transaction_id', 'network_case_id']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE disputes ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE disputes FORCE ROW LEVEL SECURITY');
            DB::statement("CREATE POLICY tenant_isolation ON disputes USING (tenant_id = current_setting('app.tenant_id', true)) WITH CHECK (tenant_id = current_setting('app.tenant_id', true))");
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION amplirail_closed_dispute_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.status IN ('won', 'lost', 'accepted') THEN
    RAISE EXCEPTION 'closed disputes are immutable' USING ERRCODE = 'insufficient_privilege';
  END IF;
  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER disputes_closed_immutable BEFORE UPDATE OR DELETE ON disputes FOR EACH ROW EXECUTE FUNCTION amplirail_closed_dispute_immutable();
SQL);
            $role = (string) config('amplirail.runtime_db_role');
            if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) && DB::selectOne('select 1 as ok from pg_roles where rolname = ?', [$role]) !== null) {
                DB::statement('GRANT SELECT, INSERT, UPDATE ON disputes TO "'.$role.'"');
                DB::statement('REVOKE DELETE, TRUNCATE ON disputes FROM "'.$role.'"');
            }
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS disputes_closed_immutable ON disputes');
            DB::statement('DROP FUNCTION IF EXISTS amplirail_closed_dispute_immutable()');
        }
        Schema::dropIfExists('disputes');
    }
};
