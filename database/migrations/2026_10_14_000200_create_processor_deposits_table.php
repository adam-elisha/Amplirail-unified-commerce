<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Processor deposit reconciliation (expected vs received per MID and period). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processor_deposits', function (Blueprint $t) {
            $t->string('id', 40)->primary();                 // dep_...
            $t->string('tenant_id', 40);
            $t->string('merchant_id', 40)->index();
            $t->string('merchant_account_id', 40)->index();
            $t->string('mid_label', 60);
            $t->string('environment', 10);
            $t->char('currency', 3);
            $t->date('deposit_date')->index();
            $t->date('period_start');
            $t->date('period_end');
            $t->bigInteger('expected_minor');
            $t->bigInteger('received_minor');
            $t->bigInteger('difference_minor');
            $t->string('reference', 120);
            $t->string('source', 20);                        // manual | csv
            $t->string('status', 20)->index();               // matched | exception | resolved
            $t->text('resolution_note')->nullable();
            $t->string('recorded_by', 60);
            $t->string('resolved_by', 60)->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'merchant_account_id', 'reference']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE processor_deposits ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE processor_deposits FORCE ROW LEVEL SECURITY');
            DB::statement("CREATE POLICY tenant_isolation ON processor_deposits USING (tenant_id = current_setting('app.tenant_id', true)) WITH CHECK (tenant_id = current_setting('app.tenant_id', true))");
            $role = (string) config('amplirail.runtime_db_role');
            if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) && DB::selectOne('select 1 as ok from pg_roles where rolname = ?', [$role]) !== null) {
                DB::statement('GRANT SELECT, INSERT, UPDATE ON processor_deposits TO "'.$role.'"');
                DB::statement('REVOKE DELETE, TRUNCATE ON processor_deposits FROM "'.$role.'"');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('processor_deposits');
    }
};
