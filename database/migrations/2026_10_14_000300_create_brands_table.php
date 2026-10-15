<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Private label (white label) brands. One brand per organization (tenant). Read before sign-in to brand the login
 * page by host name, so this is a platform table without row-level security. It holds no secrets and no card data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $t) {
            $t->string('id', 40)->primary();                 // brd_...
            $t->string('tenant_id', 40)->unique();           // organization node ID
            $t->string('partner_type', 20)->default('iso');  // iso | psp | bank | saas | other
            $t->string('name', 80);                          // product name shown in the portals
            $t->string('company', 120);                      // legal company name in footers
            $t->string('mark', 3);                           // 1 to 3 letters for the logo tile
            $t->string('tagline', 160)->nullable();
            $t->char('primary_color', 7)->default('#1A5CFF');
            $t->char('navy_color', 7)->default('#0B1F4B');
            $t->text('logo_data')->nullable();               // data: URI (PNG, JPEG or SVG, max 200 KB)
            $t->string('support_email', 120)->nullable();
            $t->string('support_phone', 40)->nullable();
            $t->string('terms_url', 300)->nullable();
            $t->string('privacy_url', 300)->nullable();
            $t->string('security_url', 300)->nullable();
            $t->jsonb('hosts');                              // ["pay.partner.com", "admin.partner.com"]
            $t->string('status', 20)->default('active');
            $t->string('created_by', 60);
            $t->timestamps();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $role = (string) config('amplirail.runtime_db_role');
            if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) && DB::selectOne('select 1 as ok from pg_roles where rolname = ?', [$role]) !== null) {
                DB::statement('GRANT SELECT, INSERT, UPDATE ON brands TO "'.$role.'"');
                DB::statement('REVOKE DELETE, TRUNCATE ON brands FROM "'.$role.'"');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
