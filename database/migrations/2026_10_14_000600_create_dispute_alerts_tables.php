<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-dispute alerts (Mastercard Ethoca, Visa Verifi CDRN and RDR, and their resellers), each merchant's alert
 * settings (fast refund on or off, up to an amount), and evidence documents attached to disputes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispute_alerts', function (Blueprint $t) {
            $t->string('id', 40)->primary();                 // alr_...
            $t->string('tenant_id', 40);
            $t->string('provider', 30);                      // ethoca | verifi_cdrn | verifi_rdr | other
            $t->string('provider_alert_id', 100);
            $t->string('merchant_id', 40)->nullable()->index();
            $t->string('transaction_id', 40)->nullable()->index();
            $t->string('environment', 10)->default('live');
            $t->bigInteger('amount_minor');
            $t->char('currency', 3);
            $t->string('card_last4', 4)->nullable();
            $t->date('transaction_date')->nullable();
            $t->string('alert_type', 30)->nullable();        // fraud | dispute
            $t->string('status', 30);                        // refunded | already_refunded | chargeback_open | declined | not_found | refund_failed | rdr_resolved
            $t->string('refund_id', 40)->nullable();
            $t->string('outcome_note', 255)->nullable();
            $t->string('received_via', 20);                  // api | manual
            $t->string('received_from', 60);                 // service client or user ID
            $t->timestamps();
            $t->unique(['tenant_id', 'provider', 'provider_alert_id']);
        });

        Schema::create('alert_settings', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('tenant_id', 40);
            $t->string('merchant_id', 40);
            $t->boolean('auto_refund')->default(false);
            $t->bigInteger('max_refund_minor')->default(0);  // 0 = no automatic refunds
            $t->string('updated_by', 60);
            $t->timestamps();
            $t->unique(['tenant_id', 'merchant_id']);
        });

        Schema::create('dispute_documents', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('tenant_id', 40);
            $t->string('dispute_id', 40)->index();
            $t->string('merchant_id', 40)->index();
            $t->string('document_id', 40);                   // merchant_documents row (encrypted file)
            $t->string('label', 120);
            $t->string('uploaded_by', 60);
            $t->timestamps();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['dispute_alerts', 'alert_settings', 'dispute_documents'] as $table) {
                DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
                DB::statement("CREATE POLICY tenant_isolation ON $table USING (tenant_id = current_setting('app.tenant_id', true)) WITH CHECK (tenant_id = current_setting('app.tenant_id', true))");
            }
        }
    }

    public function down(): void
    {
        foreach (['dispute_documents', 'alert_settings', 'dispute_alerts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
