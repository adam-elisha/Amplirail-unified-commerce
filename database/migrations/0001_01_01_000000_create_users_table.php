<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AR-007 tenancy and AR-008 identity tables. Identity tables are global (not RLS); access is enforced in code. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_nodes', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('type', 20);                // platform | organization | iso | agent | merchant | location
            $table->string('parent_id', 40)->nullable()->index();
            $table->string('tenant_id', 40)->index();  // the organization this node belongs to (itself for organizations)
            $table->string('path', 500)->index();      // /org_x/iso_y/mer_z/
            $table->string('name');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('portal', 20);              // admin | merchant
            $table->string('status', 20)->default('invited');
            $table->text('totp_secret')->nullable();   // encrypted
            $table->timestamp('totp_confirmed_at')->nullable();
            $table->bigInteger('totp_last_step')->nullable();
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('user_id', 40)->index();
            $table->string('tenant_id', 40)->index();
            $table->string('org_node_id', 40);
            $table->string('org_path', 500);
            $table->string('merchant_id', 40)->nullable()->index();
            $table->string('role', 40);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('user_invitations', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('user_id', 40)->index();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('user_id', 40)->index();
            $table->string('purpose', 20);
            $table->string('code_hash', 64);
            $table->timestamp('expires_at');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('auth_events', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 40)->nullable()->index();
            $table->string('user_id', 40)->nullable()->index();
            $table->string('email_masked')->nullable();
            $table->string('portal', 20);
            $table->string('event', 40);
            $table->string('result', 40);
            $table->string('method', 30)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->boolean('new_device')->default(false);
            $table->string('correlation_id', 80)->nullable();
            $table->timestamp('created_at')->index();
        });

        Schema::create('known_devices', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 40);
            $table->string('device_hash', 64);
            $table->timestamp('first_seen_at');
            $table->unique(['user_id', 'device_hash']);
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('tenant_id', 40)->index();
            $table->string('merchant_id', 40)->index();
            $table->string('type', 20);                // publishable | secret
            $table->string('environment', 10);         // sandbox | live
            $table->string('display_prefix', 80);
            $table->string('key_hash', 64)->unique();
            $table->jsonb('scopes')->nullable();
            $table->jsonb('ip_allowlist')->nullable();
            $table->text('pending_reveal')->nullable(); // encrypted, erased after the single reveal
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('created_by', 40)->nullable();
            $table->timestamps();
        });

        Schema::create('service_clients', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('tenant_id', 40)->index();
            $table->string('name');
            $table->string('client_id', 64)->unique();
            $table->string('secret_hash');
            $table->jsonb('scopes');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('service_tokens', function (Blueprint $table) {
            $table->string('token_hash', 64)->primary();
            $table->string('service_client_id', 40)->index();
            $table->string('tenant_id', 40);
            $table->jsonb('scopes');
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('user_id', 40)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        foreach (['sessions', 'password_reset_tokens', 'service_tokens', 'service_clients', 'api_keys', 'known_devices',
            'auth_events', 'otp_challenges', 'user_invitations', 'memberships', 'users', 'org_nodes'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
