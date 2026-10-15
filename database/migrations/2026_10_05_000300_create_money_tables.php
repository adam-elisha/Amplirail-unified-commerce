<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Refunds, payment links, webhooks, fee schedules, agreements and payout statements (AR-020/052/053, AR-146 to AR-150). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('tenant_id', 40);
            $t->string('merchant_id', 40)->index();
            $t->string('transaction_id', 40)->index();
            $t->string('environment', 10);
            $t->bigInteger('amount_minor');
            $t->char('currency', 3);
            $t->string('status', 20);
            $t->string('reason')->nullable();
            $t->string('provider_ref', 100)->nullable();
            $t->string('idempotency_key', 128);
            $t->timestamps();
        });

        Schema::create('payment_links', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('tenant_id', 40);
            $t->string('merchant_id', 40)->index();
            $t->string('environment', 10);
            $t->bigInteger('amount_minor');
            $t->char('currency', 3);
            $t->string('reference', 120);
            $t->string('description')->nullable();
            $t->text('token');
            $t->string('token_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->string('status', 20)->default('open');   // open | paid | cancelled
            $t->string('transaction_id', 40)->nullable();
            $t->jsonb('send_to')->nullable();
            $t->timestamps();
        });

        Schema::create('webhook_endpoints', function (Blueprint $t) {
            $t->string('id', 40)->primary();             // whk_...
            $t->string('tenant_id', 40);
            $t->string('merchant_id', 40)->index();
            $t->string('environment', 10);
            $t->string('url', 500);
            $t->jsonb('events');
            $t->text('secret');                          // encrypted arwhk_...
            $t->string('status', 20)->default('enabled');
            $t->string('disabled_reason')->nullable();
            $t->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->string('id', 40)->primary();             // whd_...
            $t->string('tenant_id', 40);
            $t->string('merchant_id', 40)->index();
            $t->string('endpoint_id', 40)->index();
            $t->string('event_id', 40);
            $t->string('event_type', 80);
            $t->jsonb('payload');
            $t->string('status', 20);                    // pending | delivered | failed | abandoned
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable()->index();
            $t->unsignedSmallInteger('last_status_code')->nullable();
            $t->string('last_error')->nullable();
            $t->timestamp('first_attempt_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->string('replay_of', 40)->default(''); // '' for originals so the unique key below also covers them
            $t->timestamps();
            $t->unique(['endpoint_id', 'event_id', 'replay_of']);
        });

        Schema::create('fee_schedules', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('tenant_id', 40);
            $t->string('version', 60);
            $t->char('currency', 3);
            $t->jsonb('definition');                     // core FeeSchedule format
            $t->string('status', 20)->default('active');
            $t->timestamps();
            $t->unique(['tenant_id', 'version']);
        });

        Schema::create('fee_agreements', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('tenant_id', 40);
            $t->string('merchant_id', 40)->index();
            $t->string('fee_schedule_id', 40);
            $t->string('signer_name');
            $t->timestamp('signed_at');
            $t->string('document_hash', 64);
            $t->string('status', 20)->default('signed');
            $t->string('recorded_by', 60);
            $t->timestamps();
        });

        Schema::create('payout_statements', function (Blueprint $t) {
            $t->string('id', 40)->primary();             // ps_...
            $t->string('tenant_id', 40);
            $t->string('merchant_id', 40)->index();
            $t->string('merchant_account_id', 40);
            $t->string('mid_label', 60);
            $t->date('period_start');
            $t->date('period_end');
            $t->char('currency', 3);
            $t->string('fee_schedule_id', 40);
            $t->string('fee_schedule_version', 60);
            $t->jsonb('input');
            $t->jsonb('lines');
            $t->bigInteger('gross_minor');
            $t->bigInteger('fees_minor');
            $t->bigInteger('reserve_minor');
            $t->bigInteger('net_minor');
            $t->unsignedInteger('cb_ratio_bps');
            $t->string('status', 20)->default('draft');  // draft | pending_approval | issued
            $t->string('approval_id', 40)->nullable();
            $t->timestamp('issued_at')->nullable();
            $t->timestamps();
            $t->unique(['merchant_account_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        foreach (['payout_statements', 'fee_agreements', 'fee_schedules', 'webhook_deliveries', 'webhook_endpoints', 'payment_links', 'refunds'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
