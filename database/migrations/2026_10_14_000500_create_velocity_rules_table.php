<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Velocity and limit rules at portfolio, merchant, processor (connector), BIN and card brand level. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('velocity_rules', function (Blueprint $t) {
            $t->string('id', 40)->primary();                 // vel_...
            $t->string('tenant_id', 40);
            $t->string('name', 120);
            $t->string('scope', 20);                         // portfolio | merchant | connector | bin | card_brand
            $t->string('scope_value', 60)->nullable();       // merchant ID, connector name, BIN prefix (6-8 digits) or brand
            $t->string('dimension', 20);                     // total | card | bin
            $t->string('metric', 20);                        // count | amount | single_amount
            $t->unsignedInteger('window_minutes')->default(60);
            $t->bigInteger('limit_value');                   // count, or integer minor units for amount metrics
            $t->char('currency', 3)->nullable();             // amount metrics apply only to this currency
            $t->string('environment', 10)->default('live');  // live | sandbox | any
            $t->string('action', 20)->default('block');      // block | monitor
            $t->string('status', 20)->default('active');     // active | paused
            $t->string('created_by', 60);
            $t->timestamps();
            $t->index(['tenant_id', 'status', 'scope']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE velocity_rules ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE velocity_rules FORCE ROW LEVEL SECURITY');
            DB::statement("CREATE POLICY tenant_isolation ON velocity_rules USING (tenant_id = current_setting('app.tenant_id', true)) WITH CHECK (tenant_id = current_setting('app.tenant_id', true))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('velocity_rules');
    }
};
