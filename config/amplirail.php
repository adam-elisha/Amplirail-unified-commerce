<?php

declare(strict_types=1);

/*
 * AmpliRail platform configuration. Non-secret values only.
 * Secrets are resolved by reference through Connectors\Secrets\SecretsResolver (AWS Secrets Manager in dev/prod).
 */
return [

    // Release version (VERSION file). Appended to CSS/JS URLs so long browser caches pick up each release.
    'version' => is_file(base_path('VERSION')) ? trim((string) file_get_contents(base_path('VERSION'))) : 'dev',

    // sandbox | live. Shown as the always-visible environment badge (master document 12.6).
    'environment_badge' => env('AMPLIRAIL_ENVIRONMENT_BADGE', 'sandbox'),

    // Default tenant (admin organization) used by the seeder and CLI tools when none is given.
    'default_tenant' => env('AMPLIRAIL_DEFAULT_TENANT', 'org_edatapay'),

    // Connection used for DDL (migrations, partition maintenance): the owner role, never the runtime role.
    'migration_connection' => env('DB_MIGRATION_CONNECTION', env('DB_CONNECTION', 'pgsql')),

    // PostgreSQL runtime role that receives least-privilege grants during migrations (defaults to the app's DB user).
    'runtime_db_role' => env('DB_APP_ROLE', env('DB_USERNAME', 'amplirail_app')),

    /*
     * Optional host restriction per portal. When empty, the portal answers on any host under its path prefix.
     * Paths are fixed: /admin, /app, /v1, /l/{token} (pay), /health.
     */
    'hosts' => [
        'admin' => env('ADMIN_HOST'),
        'merchant' => env('MERCHANT_HOST'),
        'api' => env('API_HOST'),
        'pay' => env('PAY_HOST'),
    ],

    'public_urls' => [
        'pay' => env('PAY_URL', env('APP_URL')),
        'merchant' => env('MERCHANT_URL', env('APP_URL')),
        'admin' => env('ADMIN_URL', env('APP_URL')),
    ],

    // Legal links shown in every footer. Built-in pages: resources/views/legal (placeholders stay in brackets until counsel fills them).
    'legal' => [
        'terms' => env('LEGAL_TERMS_URL', '/terms'),
        'privacy' => env('LEGAL_PRIVACY_URL', '/privacy'),
        'security' => env('LEGAL_SECURITY_URL', '/security'),
        'status' => env('LEGAL_STATUS_URL', '#[Status]'),
    ],

    /*
     * Feature flags (defaults). Runtime overrides live in the feature_flags table and every change is audited.
     * All flags fail closed: if the flag store cannot be read, the feature is treated as OFF.
     * go_live_gate OFF means NO merchant can be activated (V3.7.1: no manual bypass exists).
     */
    'features' => [
        'crm_handoff' => (bool) env('FEATURE_CRM_HANDOFF', false),
        'document_checklist' => (bool) env('FEATURE_DOCUMENT_CHECKLIST', true),
        'eligibility_engine' => (bool) env('FEATURE_ELIGIBILITY_ENGINE', true),
        'go_live_gate' => (bool) env('FEATURE_GO_LIVE_GATE', true),
    ],

    'auth' => [
        'otp_digits' => 8,
        'otp_ttl_minutes' => 10,
        'otp_resend_seconds' => 45,
        'otp_max_per_hour' => 5,
        'max_failed_attempts' => 5,
        'lockout_minutes' => 30,
        'admin_idle_minutes' => 15,
        'merchant_idle_minutes' => (int) env('MERCHANT_IDLE_MINUTES', 30),
        'service_token_ttl_minutes' => 15,
        'invitation_ttl_hours' => 72,
        'totp_issuer' => env('TOTP_ISSUER', 'AmpliRail'),
    ],

    'secrets' => [
        // aws | env | array. "env" is refused when APP_ENV=production.
        'driver' => env('SECRETS_DRIVER', 'env'),
        'prefix' => env('SECRETS_PREFIX', 'amplirail/local'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'cache_seconds' => 300,
        // Named platform secrets (references, not values).
        'refs' => [
            'field_encryption_key' => 'field-encryption-key',
            'fingerprint_key' => 'fingerprint-key',
            'link_signing_key' => 'link-signing-key',
        ],
    ],

    'payments' => [
        // Portal refunds above this need a step-up code (master document 5.3).
        'refund_step_up_threshold_minor' => (int) env('REFUND_STEP_UP_THRESHOLD_MINOR', 50000),
        'idempotency_ttl_hours' => 24,
        'in_flight_lock_seconds' => 60,
        // Launch-slice screening defaults; per-merchant overrides come from the merchant risk profile.
        'default_screening' => [
            'max_ticket_minor' => 500000,
            'max_per_token_per_hour' => 10,
        ],
    ],

    'webhooks' => [
        'timeout_seconds' => 10,
        // Exponential backoff for up to 72 hours, then the endpoint is disabled and the owner alerted.
        'retry_schedule_seconds' => [60, 300, 1800, 7200, 21600, 43200, 86400, 86400, 86400],
        'max_age_hours' => 72,
        // Only local/testing may deliver to http:// or private addresses.
        'allow_insecure_targets' => (bool) env('WEBHOOKS_ALLOW_INSECURE_TARGETS', false),
    ],

    'documents' => [
        'disk' => env('DOCUMENTS_DISK', 'documents'),
        'max_kilobytes' => 10240,
        'allowed_mimes' => ['application/pdf', 'image/jpeg', 'image/png'],
        'download_ttl_minutes' => 5,
    ],

    'outbox' => [
        // redis_streams | log | null
        'publisher' => env('OUTBOX_PUBLISHER', 'log'),
        'stream' => env('OUTBOX_STREAM', 'amplirail:events'),
        'batch_size' => 200,
    ],

    /*
     * Activation SLA command center (AR-173): owner, next action and SLA per onboarding state.
     * hours = business target for the state inside the 4-day plan.
     */
    'onboarding' => [
        'sla' => [
            'draft' => ['owner' => 'Agent', 'next_action' => 'Complete the application in the CRM', 'hours' => 8],
            'submitted' => ['owner' => 'Merchant', 'next_action' => 'Upload the required documents', 'hours' => 24],
            'needs_info' => ['owner' => 'Merchant', 'next_action' => 'Provide the requested information', 'hours' => 24],
            'kyb_pending' => ['owner' => 'Operations', 'next_action' => 'Complete KYB and KYC checks', 'hours' => 8],
            'manual_review' => ['owner' => 'Risk', 'next_action' => 'Decide the manual review case', 'hours' => 8],
            'underwriting' => ['owner' => 'Risk', 'next_action' => 'Record the underwriting decision', 'hours' => 16],
            'approved' => ['owner' => 'Operations', 'next_action' => 'Submit to the eligible processor route', 'hours' => 8],
            'boarding' => ['owner' => 'Operations', 'next_action' => 'Obtain processor approval, record MID and credentials', 'hours' => 24],
            'test_mode' => ['owner' => 'Technical', 'next_action' => 'Run validation (test sale, refund, webhook)', 'hours' => 16],
            'validation' => ['owner' => 'Operations', 'next_action' => 'Request go-live and obtain second approval', 'hours' => 8],
        ],
        // Pipeline buckets on the Overview screen.
        'pipeline' => [
            'New' => ['draft', 'submitted'],
            'KYB/KYC' => ['kyb_pending', 'needs_info'],
            'Risk' => ['underwriting', 'manual_review'],
            'Processor' => ['approved', 'boarding'],
            'Test' => ['test_mode', 'validation'],
            'Live' => ['active'],
        ],
        // Rows past this fraction of the state SLA are highlighted as at risk.
        'at_risk_fraction' => 0.75,
    ],

    'statements' => [
        'cycle' => 'weekly',
        'paid_in_arrears_days' => 7,
    ],
];
