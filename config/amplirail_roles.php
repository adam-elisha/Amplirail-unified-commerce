<?php

declare(strict_types=1);

/*
 * Roles and permissions (master document 5.3). Roles are granted at an org node and inherit downward.
 * Changing this file changes access: it is protected by CODEOWNERS and needs a second reviewer.
 */
$all = [
    'merchants.view', 'merchants.create', 'merchants.edit', 'merchants.go_live.request', 'merchants.go_live.approve',
    'payments.view', 'payments.refund', 'payments.void', 'routing.view', 'routing.draft', 'routing.publish',
    'recon.view', 'recon.resolve', 'risk.view', 'risk.decide', 'residuals.view', 'residuals.approve_payout',
    'users.manage', 'roles.manage', 'api_keys.manage', 'webhooks.manage', 'support.view', 'support.reply',
    'devices.manage', 'audit.view', 'settings.security.manage', 'merchants.risk.edit', 'merchants.limits.edit',
    'merchants.descriptors.edit', 'merchants.view_as', 'reports.export', 'fees.view', 'fees.manage', 'fees.approve',
    'documents.view', 'documents.manage', 'approvals.view',
];

return [
    'permissions' => $all,

    'roles' => [
        // Admin console (eDataPay staff).
        'platform_owner' => ['portal' => 'admin', 'permissions' => array_merge($all, ['platform.emergency_override'])],
        'owner' => ['portal' => 'admin', 'permissions' => $all],
        'operations' => ['portal' => 'admin', 'permissions' => [
            'merchants.view', 'merchants.create', 'merchants.edit', 'merchants.go_live.request', 'merchants.go_live.approve',
            'payments.view', 'payments.refund', 'payments.void', 'routing.view', 'routing.draft', 'recon.view', 'risk.view',
            'support.view', 'support.reply', 'documents.view', 'documents.manage', 'approvals.view', 'webhooks.manage',
            'merchants.risk.edit', 'merchants.limits.edit', 'merchants.descriptors.edit', 'fees.view', 'reports.export',
        ]],
        'finance' => ['portal' => 'admin', 'permissions' => [
            'merchants.view', 'payments.view', 'recon.view', 'recon.resolve', 'residuals.view', 'residuals.approve_payout',
            'fees.view', 'fees.manage', 'fees.approve', 'reports.export', 'approvals.view',
        ]],
        'underwriter' => ['portal' => 'admin', 'permissions' => [
            'merchants.view', 'risk.view', 'risk.decide', 'documents.view', 'approvals.view', 'payments.view',
        ]],
        'support_agent' => ['portal' => 'admin', 'permissions' => [
            'merchants.view', 'payments.view', 'support.view', 'support.reply',
        ]],
        'developer' => ['portal' => 'admin', 'permissions' => [
            'merchants.view', 'payments.view', 'routing.view', 'routing.draft', 'webhooks.manage', 'audit.view',
        ]],
        'read_only' => ['portal' => 'admin', 'permissions' => [
            'merchants.view', 'payments.view', 'routing.view', 'recon.view', 'risk.view', 'fees.view', 'approvals.view',
        ]],

        // Merchant portal.
        'merchant_owner' => ['portal' => 'merchant', 'permissions' => [
            'payments.view', 'payments.refund', 'payments.void', 'api_keys.manage', 'webhooks.manage',
            'documents.view', 'documents.manage', 'reports.export', 'fees.view',
        ]],
        'merchant_staff' => ['portal' => 'merchant', 'permissions' => [
            'payments.view', 'documents.view',
        ]],
    ],

    // Approval types and the permission an approver must hold. Requester can never approve (enforced in code).
    'approvals' => [
        'go_live' => 'merchants.go_live.approve',
        'go_live_override' => 'merchants.go_live.approve',
        'eligibility_publish' => 'routing.publish',
        'statement_issue' => 'fees.approve',
        'reserve_release' => 'fees.approve',
    ],
];
