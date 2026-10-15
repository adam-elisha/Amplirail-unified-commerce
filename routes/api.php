<?php

declare(strict_types=1);

use App\Http\Controllers\Api\HandoffController;
use App\Http\Controllers\Api\MerchantApiController;
use App\Http\Controllers\Api\PaymentLinkApiController;
use App\Http\Controllers\Api\RefundApiController;
use App\Http\Controllers\Api\ServiceTokenController;
use App\Http\Controllers\Api\StatementApiController;
use App\Http\Controllers\Api\TransactionApiController;
use App\Http\Controllers\Api\WebhookEndpointApiController;
use Illuminate\Support\Facades\Route;

/*
 * AmpliRail API v1 (openapi/amplirail-v1.yaml is the contract). Prefix /v1 is applied in bootstrap/app.php.
 * Merchant secret keys: auth.apikey. Internal services (CRM, RoboRisk): auth.service:<scope>.
 */

Route::post('oauth/token', [ServiceTokenController::class, 'issue'])->middleware('throttle:oauth')->name('api.oauth.token');

// Internal services (OAuth2 client credentials).
Route::middleware(['auth.service:crm.handoff', 'idempotent', 'throttle:api'])->group(function (): void {
    Route::post('merchants/handoff', [HandoffController::class, 'store'])->name('api.merchants.handoff');
});
Route::middleware(['auth.service:merchants.read', 'throttle:api'])->group(function (): void {
    Route::get('merchants/{merchant}', [MerchantApiController::class, 'show'])->name('api.merchants.show');
    Route::get('merchants/{merchant}/eligibility', [MerchantApiController::class, 'eligibility'])->name('api.merchants.eligibility');
    Route::get('merchants/{merchant}/documents', [MerchantApiController::class, 'documents'])->name('api.merchants.documents');
    Route::get('onboarding/command_center', [MerchantApiController::class, 'commandCenter'])->name('api.onboarding.command_center');
});

// Pre-dispute alert partners (Ethoca / Verifi and resellers). Repeating an alert_id returns the first answer.
Route::middleware(['auth.service:alerts.write', 'throttle:api'])->group(function (): void {
    Route::post('alerts', [\App\Http\Controllers\Api\AlertApiController::class, 'store'])->name('api.alerts.store');
});

// Merchant secret keys.
Route::middleware(['auth.apikey', 'idempotent', 'throttle:api'])->group(function (): void {
    Route::get('merchant', [MerchantApiController::class, 'self'])->name('api.merchant.self');
    Route::post('merchant/documents', [MerchantApiController::class, 'upload'])->name('api.merchant.documents.upload');

    Route::post('transactions', [TransactionApiController::class, 'store'])->name('api.transactions.store');
    Route::get('transactions', [TransactionApiController::class, 'index'])->name('api.transactions.index');
    Route::get('transactions/{id}', [TransactionApiController::class, 'show'])->name('api.transactions.show');
    Route::get('transactions/{id}/timeline', [TransactionApiController::class, 'timeline'])->name('api.transactions.timeline');
    Route::post('transactions/{id}/capture', [TransactionApiController::class, 'capture'])->name('api.transactions.capture');
    Route::post('transactions/{id}/void', [TransactionApiController::class, 'void'])->name('api.transactions.void');

    Route::post('refunds', [RefundApiController::class, 'store'])->name('api.refunds.store');
    Route::get('refunds', [RefundApiController::class, 'index'])->name('api.refunds.index');
    Route::get('refunds/{id}', [RefundApiController::class, 'show'])->name('api.refunds.show');

    Route::post('payment_links', [PaymentLinkApiController::class, 'store'])->name('api.payment_links.store');
    Route::get('payment_links', [PaymentLinkApiController::class, 'index'])->name('api.payment_links.index');

    Route::get('payout_statements', [StatementApiController::class, 'index'])->name('api.statements.index');
    Route::get('payout_statements/{id}', [StatementApiController::class, 'show'])->name('api.statements.show');

    Route::post('webhook_endpoints', [WebhookEndpointApiController::class, 'store'])->name('api.webhooks.store');
    Route::get('webhook_endpoints', [WebhookEndpointApiController::class, 'index'])->name('api.webhooks.index');
    Route::post('webhook_endpoints/{id}/rotate_secret', [WebhookEndpointApiController::class, 'rotate'])->name('api.webhooks.rotate');
    Route::post('webhook_endpoints/{id}/test', [WebhookEndpointApiController::class, 'test'])->name('api.webhooks.test');
});
