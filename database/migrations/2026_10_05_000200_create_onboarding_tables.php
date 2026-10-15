<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Onboarding and the Sprint 0.5 activation engine (AR-168 to AR-173), routing MIDs and approvals. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $t) {
            $t->string('id', 40)->primary();                  // mer_...  one permanent eDataPay Merchant ID
            $t->string('tenant_id', 40)->index();
            $t->string('org_node_id', 40);
            $t->string('org_path', 500)->index();
            $t->string('public_id', 20)->unique();            // EDP-M-00012871
            $t->string('crm_deal_id', 80)->nullable();        // immutable once set (trigger)
            $t->string('source', 10);                         // crm | manual
            $t->string('merchant_type', 20);                  // standard | mobile | test
            $t->string('state', 20)->index();
            $t->string('previous_state', 20)->nullable();     // resume target for needs_info / suspended
            $t->timestamp('state_changed_at');
            $t->string('legal_name');
            $t->string('dba')->nullable();
            $t->text('ein')->nullable();                      // encrypted
            $t->string('ein_fingerprint', 64)->nullable()->index();
            $t->string('entity_type', 40)->nullable();
            $t->unsignedSmallInteger('years_in_business')->nullable();
            $t->string('website')->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('timezone', 60)->default('America/New_York');
            $t->text('business_description')->nullable();
            $t->jsonb('address');
            $t->text('contacts')->nullable();                 // encrypted JSON
            $t->text('owners')->nullable();                   // encrypted JSON (owners/UBOs)
            $t->string('bank_token_ref', 120)->nullable();    // settlement bank TOKEN reference only
            $t->string('bank_last4', 4)->nullable();
            $t->string('mcc', 4);
            $t->string('mcc_secondary', 4)->nullable();
            $t->char('country', 2);
            $t->string('region', 10)->nullable();
            $t->jsonb('channels');
            $t->bigInteger('monthly_volume_minor');
            $t->bigInteger('avg_ticket_minor');
            $t->bigInteger('max_ticket_minor');
            $t->char('currency', 3);
            $t->unsignedTinyInteger('cnp_percent')->default(100);
            $t->boolean('recurring')->default(false);
            $t->unsignedInteger('chargeback_ratio_bps')->nullable();
            $t->jsonb('agent')->nullable();                   // original agent, locked (trigger)
            $t->jsonb('application')->nullable();             // non-sensitive extras: products, policies, services
            $t->string('kyb_status', 20)->default('pending');
            $t->string('risk_tier', 10)->nullable();
            $t->jsonb('risk_profile')->nullable();            // max_ticket_minor, reserve_bps, funding_delay_days, ...
            $t->string('descriptor', 25)->nullable();
            $t->string('pci_status', 20)->nullable();
            $t->date('pci_expires_at')->nullable();
            $t->string('fee_schedule_id', 40)->nullable();
            $t->string('payout_method', 20)->nullable();
            $t->string('funding_schedule', 20)->nullable();
            $t->timestamp('settlement_verified_at')->nullable();
            $t->text('sandbox_webhook_secret')->nullable();   // encrypted arwhk_sbx_
            $t->string('processor_route', 60)->nullable();
            $t->string('processor_reference', 80)->nullable();
            foreach (['sandbox_provisioned_at', 'documents_completed_at', 'kyb_passed_at', 'risk_approved_at', 'processor_submitted_at',
                'processor_approved_at', 'mid_created_at', 'credentials_created_at', 'test_completed_at', 'live_at'] as $c) {
                $t->timestamp($c)->nullable();
            }
            $t->timestamps();
            $t->unique(['tenant_id', 'crm_deal_id']);
        });

        // Public flows (pay pages) resolve a merchant's tenant here before a tenant context exists.
        Schema::create('merchant_index', function (Blueprint $t) {
            $t->string('merchant_id', 40)->primary();
            $t->string('tenant_id', 40);
        });

        Schema::create('merchant_state_transitions', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id', 40);
            $t->string('merchant_id', 40)->index();
            $t->string('from_state', 20)->nullable();
            $t->string('to_state', 20);
            $t->string('event', 60);
            $t->string('event_id', 40)->nullable();
            $t->string('actor_type', 20);
            $t->string('actor_id', 60);
            $t->text('reason')->nullable();
            $t->string('correlation_id', 80)->nullable();
            $t->timestamp('created_at')->index();
        });

        Schema::create('risk_cases', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('tenant_id', 40);
            $t->string('merchant_id', 40)->index();
            $t->string('provider', 40);
            $t->string('reference', 80);
            $t->unsignedTinyInteger('score');
            $t->string('band', 10);
            $t->unsignedTinyInteger('confidence');
            $t->string('recommendation', 20);
            $t->jsonb('signals');
            $t->jsonb('recommended_controls');
            $t->boolean('gate_complete');
            $t->string('status', 20)->default('open');
            $t->string('decision', 20)->nullable();
            $t->string('decided_by', 40)->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_reason')->nullable();
            $t->timestamps();
        });

        Schema::create('kyb_checks', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('tenant_id', 40);
            $t->string('merchant_id', 40)->index();
            $t->string('provider', 40);
            $t->string('reference', 80);
            $t->string('status', 20);
            $t->jsonb('reasons');
            $t->timestamp('created_at');
        });

        Schema::create('document_requirements', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('tenant_id', 40)->index();
            $t->string('document_type', 60);
            $t->string('label');
            $t->string('stage', 20);                      // the onboarding stage that needs it (submitted | boarding)
            $t->jsonb('applies_to')->nullable();          // {mcc_in, mcc_not_in, route_in, channel_any, min_monthly_volume_minor}
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('effective_from');
            $t->timestamps();
        });

        Schema::create('merchant_documents', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('tenant_id', 40);
            $t->string('merchant_id', 40)->index();
            $t->string('document_type', 60);
            $t->string('original_name', 150);
            $t->string('mime', 60);
            $t->unsignedInteger('size_bytes');
            $t->string('sha256', 64);
            $t->string('storage_disk', 20);
            $t->string('storage_path', 200);
            $t->string('status', 20)->default('active');  // active | removed | rejected
            $t->string('uploaded_by_type', 20);
            $t->string('uploaded_by', 60);
            $t->timestamp('removed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('document_requests', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('tenant_id', 40);
            $t->string('merchant_id', 40)->index();
            $t->string('document_type', 60);
            $t->string('label');
            $t->text('reason')->nullable();
            $t->string('source', 20);                     // processor | risk | operations
            $t->string('status', 20)->default('open');    // open | satisfied
            $t->string('satisfied_by_document_id', 40)->nullable();
            $t->timestamp('satisfied_at')->nullable();
            $t->timestamps();
        });

        Schema::create('eligibility_rule_sets', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('tenant_id', 40);
            $t->unsignedInteger('version');
            $t->string('status', 20);                     // draft | pending_approval | published | superseded | rejected
            $t->timestamp('effective_from')->nullable();
            $t->jsonb('rules');
            $t->text('notes')->nullable();
            $t->string('created_by', 60);
            $t->string('approval_id', 40)->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'version']);
        });

        Schema::create('eligibility_results', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id', 40);
            $t->string('merchant_id', 40)->index();
            $t->string('rule_set_id', 40);
            $t->unsignedInteger('rule_set_version');
            $t->jsonb('eligible');
            $t->jsonb('ineligible');
            $t->timestamp('evaluated_at');
        });

        Schema::create('approvals', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('tenant_id', 40);
            $t->string('type', 40);
            $t->string('subject_type', 40);
            $t->string('subject_id', 40);
            $t->string('status', 20)->index();            // pending | approved | rejected | failed
            $t->jsonb('payload');
            $t->text('reason')->nullable();
            $t->string('requested_by', 60);
            $t->timestamp('requested_at');
            $t->string('decided_by', 60)->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->jsonb('result')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'subject_id']);
        });

        Schema::create('validation_runs', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('tenant_id', 40);
            $t->string('merchant_id', 40)->index();
            $t->string('status', 20);
            $t->jsonb('checks');
            $t->string('created_by', 60);
            $t->timestamp('created_at');
        });

        Schema::create('go_live_evaluations', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id', 40);
            $t->string('merchant_id', 40)->index();
            $t->string('actor_id', 60);
            $t->boolean('passed');
            $t->jsonb('checks');
            $t->timestamp('created_at');
        });

        Schema::create('merchant_accounts', function (Blueprint $t) {
            $t->string('id', 40)->primary();              // macc_...
            $t->string('tenant_id', 40);
            $t->string('merchant_id', 40)->index();
            $t->string('connector', 40);
            $t->string('environment', 10);                // sandbox | live
            $t->string('label', 40);                      // display MID, e.g. TMID-40021
            $t->string('provider_mid', 60)->nullable();
            $t->string('provider_tid', 60)->nullable();
            $t->char('currency', 3);
            $t->unsignedSmallInteger('priority')->default(1);
            $t->string('status', 20);                     // active | inactive | pending
            $t->bigInteger('max_ticket_minor')->nullable();
            $t->string('credential_ref', 200)->nullable(); // secrets-manager reference, never the credential
            $t->string('descriptor', 25)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['merchant_accounts', 'go_live_evaluations', 'validation_runs', 'approvals', 'eligibility_results', 'eligibility_rule_sets',
            'document_requests', 'merchant_documents', 'document_requirements', 'kyb_checks', 'risk_cases', 'merchant_state_transitions',
            'merchant_index', 'merchants'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
