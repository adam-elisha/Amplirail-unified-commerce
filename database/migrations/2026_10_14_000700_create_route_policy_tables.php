<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MID controls (caps, pause), BIN routing preferences, the BIN reference table, and platform fee rules (partner markup).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mid_controls', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('tenant_id', 40);
            $t->string('merchant_account_id', 40);
            $t->string('merchant_id', 40)->index();
            $t->bigInteger('monthly_cap_minor')->nullable();  // null = no cap
            $t->bigInteger('daily_cap_minor')->nullable();
            $t->boolean('paused')->default(false);
            $t->string('note', 255)->nullable();
            $t->string('updated_by', 60);
            $t->timestamps();
            $t->unique(['tenant_id', 'merchant_account_id']);
        });

        Schema::create('bin_route_preferences', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('tenant_id', 40);
            $t->string('name', 120);
            $t->string('merchant_id', 40)->nullable();       // null = every merchant
            $t->string('bin_prefix', 8)->nullable();
            $t->string('card_brand', 20)->nullable();
            $t->string('card_type', 20)->nullable();         // credit | debit | prepaid
            $t->char('issuer_country', 2)->nullable();
            $t->boolean('commercial')->nullable();
            $t->jsonb('preferred_connectors');               // ordered list, e.g. ["gattera_bridge","nmi_bridge"]
            $t->unsignedInteger('priority')->default(100);   // lower wins when several preferences match
            $t->string('status', 20)->default('active');
            $t->string('created_by', 60);
            $t->timestamps();
        });

        // Reference data, shared by every organization (no card numbers: prefixes only).
        Schema::create('bin_ranges', function (Blueprint $t) {
            $t->string('bin_prefix', 8)->primary();
            $t->string('card_brand', 20)->nullable();
            $t->string('card_type', 20)->nullable();
            $t->string('card_tier', 30)->nullable();
            $t->string('issuer_name', 120)->nullable();
            $t->char('issuer_country', 2)->nullable();
            $t->boolean('commercial')->default(false);
            $t->timestamps();
        });

        Schema::create('platform_fee_rules', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('tenant_id', 40);
            $t->string('merchant_id', 40)->index();
            $t->string('partner_key', 60);                   // ledger account suffix: partner_payable:{partner_key}
            $t->string('partner_name', 120);
            $t->unsignedInteger('percent_bps')->default(0);  // 25 = 0.25% of gross sales
            $t->bigInteger('fixed_minor')->default(0);       // per approved sale
            $t->char('currency', 3);
            $t->string('status', 20)->default('active');
            $t->string('created_by', 60);
            $t->timestamps();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['mid_controls', 'bin_route_preferences', 'platform_fee_rules'] as $table) {
                DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
                DB::statement("CREATE POLICY tenant_isolation ON $table USING (tenant_id = current_setting('app.tenant_id', true)) WITH CHECK (tenant_id = current_setting('app.tenant_id', true))");
            }
        }
    }

    public function down(): void
    {
        foreach (['platform_fee_rules', 'bin_ranges', 'bin_route_preferences', 'mid_controls'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
