<?php

declare(strict_types=1);

/*
 * Connector manifests (master document 7). Routing reads these so unsupported features are never sent.
 * Provider credentials are NEVER here: each MerchantAccount holds a credential_ref resolved through the secrets manager.
 */
return [
    'simulator' => [
        'name' => 'AmpliRail SIM (test)',
        'enabled' => true,
        'environments' => ['sandbox'],
        'operations' => ['sale', 'authorize', 'capture', 'refund', 'void'],
        'currencies' => ['USD', 'EUR', 'GBP', 'CAD'],
        'channels' => ['ecommerce', 'moto', 'virtual_terminal', 'payment_link', 'recurring'],
        'three_ds' => false,
    ],

    // Hidden NMI bridge (D29). Card entry only through NMI hosted fields (Collect.js). Merchants never see "NMI".
    'nmi_bridge' => [
        'name' => 'Card bridge US',
        'enabled' => (bool) env('CONNECTOR_NMI_ENABLED', false),
        'environments' => ['live'],
        'endpoint' => env('NMI_TRANSACT_URL', ''),          // set from docs/providers/nmi/ once access is confirmed
        'collect_js_url' => env('NMI_COLLECT_JS_URL', ''),   // hosted fields script, added to the pay-page CSP
        'public_tokenization_key' => env('NMI_PUBLIC_TOKENIZATION_KEY', ''), // public (browser) key; NOT the security key
        'timeout_ms' => 8000,
        'operations' => ['sale', 'authorize', 'capture', 'refund', 'void'],
        'currencies' => ['USD'],
        'channels' => ['ecommerce', 'moto', 'virtual_terminal', 'payment_link', 'recurring'],
        'three_ds' => false,
    ],

    // Hidden Gattera bridge (D29, D35). BLOCKED until Gattera documentation and hosted fields/tokens are confirmed.
    'gattera_bridge' => [
        'name' => 'Card bridge EU',
        'enabled' => (bool) env('CONNECTOR_GATTERA_ENABLED', false),
        'environments' => ['live'],
        'base_url' => env('GATTERA_BASE_URL', ''),
        'timeout_ms' => 8000,
        'operations' => ['sale', 'authorize', 'capture', 'refund', 'void'],
        'currencies' => ['EUR', 'GBP', 'PLN', 'CZK', 'RON', 'HUF', 'SEK', 'DKK'],
        'channels' => ['ecommerce', 'payment_link'],
        'three_ds' => true,
    ],

    // Circuit breaker shared by HTTP connectors.
    'circuit_breaker' => [
        'failure_threshold' => 5,
        'open_seconds' => 30,
    ],
];
