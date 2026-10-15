<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Issuer fraud reports received through the acquirer or processor: Visa TC40 and Mastercard SAFE. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_fraud_reports', function (Blueprint $t) {
            $t->string('id', 40)->primary();                 // frd_...
            $t->string('tenant_id', 40);
            $t->string('merchant_id', 40)->index();
            $t->string('merchant_account_id', 40)->nullable();
            $t->string('transaction_id', 40)->nullable()->index();
            $t->string('environment', 10);
            $t->string('network', 20);                       // visa | mastercard
            $t->string('report_type', 10);                   // tc40 | safe
            $t->string('fraud_type', 40)->nullable();        // as reported (e.g. lost, stolen, card not present, counterfeit)
            $t->string('network_ref', 80);                   // the report's own reference
            $t->date('report_date')->index();
            $t->bigInteger('amount_minor');
            $t->char('currency', 3);
            $t->string('source', 20);                        // csv | manual
            $t->string('recorded_by', 60);
            $t->timestamps();
            $t->unique(['tenant_id', 'network', 'network_ref']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE network_fraud_reports ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE network_fraud_reports FORCE ROW LEVEL SECURITY');
            DB::statement("CREATE POLICY tenant_isolation ON network_fraud_reports USING (tenant_id = current_setting('app.tenant_id', true)) WITH CHECK (tenant_id = current_setting('app.tenant_id', true))");
            $role = (string) config('amplirail.runtime_db_role');
            if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) && DB::selectOne('select 1 as ok from pg_roles where rolname = ?', [$role]) !== null) {
                DB::statement('GRANT SELECT, INSERT ON network_fraud_reports TO "'.$role.'"');
                DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON network_fraud_reports FROM "'.$role.'"');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('network_fraud_reports');
    }
};
