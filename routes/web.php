<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminStepUpController;
use App\Http\Controllers\Admin\AlertController;
use App\Http\Controllers\Admin\BinRoutingController;
use App\Http\Controllers\Admin\ApprovalController;
use App\Http\Controllers\Admin\DevelopersController;
use App\Http\Controllers\Admin\DisputeAnalyticsController;
use App\Http\Controllers\Admin\DisputeController;
use App\Http\Controllers\Admin\ManagementController;
use App\Http\Controllers\Admin\MerchantActionController;
use App\Http\Controllers\Admin\MerchantController;
use App\Http\Controllers\Admin\MidControlController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\PartnerController;
use App\Http\Controllers\Admin\PlatformFeeController;
use App\Http\Controllers\Admin\ReconciliationController;
use App\Http\Controllers\Admin\ReserveController;
use App\Http\Controllers\Admin\RiskMonitoringController;
use App\Http\Controllers\Admin\RoutingController;
use App\Http\Controllers\Admin\StatementController;
use App\Http\Controllers\Admin\TransactionController;
use App\Http\Controllers\Admin\VelocityController;
use App\Http\Controllers\Auth\SignInController;
use App\Http\Controllers\DisputeEvidenceController;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Merchant\MerchantAlertController;
use App\Http\Controllers\Merchant\MerchantDisputeController;
use App\Http\Controllers\Merchant\MerchantPortalController;
use App\Http\Controllers\PayController;
use Illuminate\Support\Facades\Route;

/*
 * Portals: /admin (admin.amplirail.com), /app (app.amplirail.com), /l/{token} (pay.amplirail.com), /health.
 * Optional host pinning per portal via ADMIN_HOST / MERCHANT_HOST / PAY_HOST.
 */

Route::get('/health', HealthController::class)->name('health');
Route::get('/', fn () => view('home'))->name('home');

// Public legal pages (footer links on every portal open them in a popup; the URLs also work directly).
Route::view('/terms', 'legal.terms')->name('legal.terms');
Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/security', 'legal.security')->name('legal.security');

// Sign-in routes for both portals.
foreach (['admin' => 'admin', 'merchant' => 'app'] as $portal => $prefix) {
    Route::prefix($prefix)->name($portal.'.')->group(function () use ($portal): void {
        Route::get('login', [SignInController::class, 'show'])->name('login');
        Route::post('login', [SignInController::class, 'login'])->middleware('throttle:sign-in')->name('login.submit');
        Route::get('verify', [SignInController::class, 'verifyForm'])->name('verify');
        Route::post('verify', [SignInController::class, 'verify'])->middleware('throttle:otp')->name('verify.submit');
        Route::post('logout', [SignInController::class, 'logout'])->name('logout');
        Route::get('invitation/{token}', [SignInController::class, 'invitationForm'])->name('invitation');
        Route::post('invitation/{token}', [SignInController::class, 'acceptInvitation'])->middleware('throttle:sign-in')->name('invitation.submit');
        if ($portal === 'admin') {
            Route::get('authenticator', [SignInController::class, 'totpForm'])->name('totp');
            Route::post('authenticator', [SignInController::class, 'totpConfirm'])->middleware('throttle:otp')->name('totp.submit');
        } else {
            Route::post('verify/resend', [SignInController::class, 'resend'])->middleware('throttle:otp')->name('verify.resend');
        }
    });
}

// ----- Admin console -----
$admin = Route::prefix('admin')->name('admin.')->middleware(['auth', 'portal:admin']);
if (is_string($host = config('amplirail.hosts.admin')) && $host !== '') {
    $admin->domain($host);
}
$admin->group(function (): void {
    Route::get('/', [OverviewController::class, 'index'])->middleware('permission:merchants.view')->name('overview');

    Route::get('approvals', [ApprovalController::class, 'index'])->middleware('permission:approvals.view')->name('approvals');
    Route::post('approvals/{approval}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
    Route::post('approvals/{approval}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');

    Route::get('merchants', [MerchantController::class, 'index'])->middleware('permission:merchants.view')->name('merchants.index');
    Route::get('merchants/create', [MerchantController::class, 'create'])->middleware('permission:merchants.create')->name('merchants.create');
    Route::post('merchants', [MerchantController::class, 'store'])->middleware('permission:merchants.create')->name('merchants.store');
    Route::get('merchants/{merchant}', [MerchantController::class, 'show'])->middleware('permission:merchants.view')->name('merchants.show');

    Route::controller(MerchantActionController::class)->prefix('merchants/{merchant}')->name('merchants.')->group(function (): void {
        Route::post('documents', 'upload')->middleware('permission:documents.manage')->name('documents.upload');
        Route::post('documents/{document}/remove', 'removeDocument')->middleware('permission:documents.manage')->name('documents.remove');
        Route::post('documents-complete', 'documentsComplete')->middleware('permission:documents.manage')->name('documents.complete');
        Route::post('request-info', 'requestInfo')->middleware('permission:documents.manage')->name('request-info');
        Route::post('kyb/run', 'runKyb')->middleware('permission:merchants.edit')->name('kyb.run');
        Route::post('kyb/resolve', 'resolveKyb')->middleware('permission:risk.decide')->name('kyb.resolve');
        Route::post('risk/assess', 'assessRisk')->middleware('permission:risk.view')->name('risk.assess');
        Route::post('risk/decide', 'decideRisk')->middleware('permission:risk.decide')->name('risk.decide');
        Route::post('eligibility', 'evaluateEligibility')->middleware('permission:routing.view')->name('eligibility');
        Route::post('submit', 'submitToProcessor')->middleware('permission:merchants.edit')->name('submit');
        Route::post('processor-approved', 'processorApproved')->middleware('permission:merchants.edit')->name('processor-approved');
        Route::post('mid', 'recordMid')->middleware('permission:merchants.edit')->name('mid');
        Route::post('credentials', 'recordCredentials')->middleware('permission:merchants.edit')->name('credentials');
        Route::post('configure', 'configure')->middleware('permission:merchants.risk.edit')->name('configure');
        Route::post('agreement', 'recordAgreement')->middleware('permission:fees.manage')->name('agreement');
        Route::post('validate', 'runValidation')->middleware('permission:merchants.edit')->name('validate');
        Route::post('go-live', 'requestGoLive')->middleware('permission:merchants.go_live.request')->name('go-live');
        Route::post('go-live-override', 'requestOverride')->middleware('permission:merchants.go_live.approve')->name('go-live-override');
        Route::post('suspend', 'suspend')->middleware('permission:merchants.edit')->name('suspend');
        Route::post('reinstate', 'reinstate')->middleware('permission:merchants.edit')->name('reinstate');
    });

    Route::get('documents/{document}/download', [DocumentDownloadController::class, 'admin'])->middleware(['signed', 'permission:documents.view'])->name('documents.download');

    Route::get('transactions', [TransactionController::class, 'index'])->middleware('permission:payments.view')->name('transactions.index');
    Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->middleware('permission:payments.view')->name('transactions.show');
    Route::post('transactions/{transaction}/refund', [TransactionController::class, 'refund'])->middleware('permission:payments.refund')->name('transactions.refund');
    Route::post('transactions/{transaction}/void', [TransactionController::class, 'void'])->middleware('permission:payments.void')->name('transactions.void');
    Route::post('webhook-deliveries/{delivery}/replay', [TransactionController::class, 'replay'])->middleware('permission:webhooks.manage')->name('webhooks.replay');

    // Disputes and chargebacks (2026-10-14).
    Route::get('disputes', [DisputeController::class, 'index'])->middleware('permission:payments.view')->name('disputes.index');
    Route::post('disputes', [DisputeController::class, 'store'])->middleware('permission:payments.refund')->name('disputes.store');
    Route::get('disputes/{dispute}', [DisputeController::class, 'show'])->middleware('permission:payments.view')->name('disputes.show');
    Route::post('disputes/{dispute}/resolve', [DisputeController::class, 'resolve'])->middleware('permission:payments.refund')->name('disputes.resolve');
    Route::post('disputes/{dispute}/respond', [DisputeController::class, 'respond'])->middleware('permission:payments.refund')->name('disputes.respond');

    // Reconciliation, reserves and emailed step-up codes (2026-10-14).
    Route::get('reconciliation', [ReconciliationController::class, 'index'])->middleware('permission:recon.view')->name('recon.index');
    Route::post('reconciliation', [ReconciliationController::class, 'store'])->middleware('permission:recon.resolve')->name('recon.store');
    Route::post('reconciliation/import', [ReconciliationController::class, 'import'])->middleware('permission:recon.resolve')->name('recon.import');
    Route::post('reconciliation/{deposit}/resolve', [ReconciliationController::class, 'resolve'])->middleware('permission:recon.resolve')->name('recon.resolve');
    Route::get('reserves', [ReserveController::class, 'index'])->middleware('permission:fees.view')->name('reserves.index');
    Route::post('reserves/release', [ReserveController::class, 'release'])->middleware('permission:fees.manage')->name('reserves.release');
    Route::post('step-up', [AdminStepUpController::class, 'send'])->middleware('throttle:otp')->name('step-up.send');

    // Private label partners (platform owners only).
    Route::get('partners', [PartnerController::class, 'index'])->middleware('permission:platform.emergency_override')->name('partners.index');
    Route::post('partners', [PartnerController::class, 'store'])->middleware('permission:platform.emergency_override')->name('partners.store');
    Route::post('partners/{org}', [PartnerController::class, 'update'])->middleware('permission:platform.emergency_override')->name('partners.update');

    // Network fraud reports and Visa VAMP monitoring (round 4).
    Route::get('risk/vamp', [RiskMonitoringController::class, 'index'])->middleware('permission:risk.view')->name('risk.vamp');
    Route::post('risk/vamp/import', [RiskMonitoringController::class, 'import'])->middleware('permission:risk.decide')->name('risk.vamp.import');

    // Velocity limits, pre-dispute alerts, chargeback outcomes and dispute evidence (round 5).
    Route::get('risk/velocity', [VelocityController::class, 'index'])->middleware('permission:risk.view')->name('risk.velocity');
    Route::post('risk/velocity', [VelocityController::class, 'store'])->middleware('permission:merchants.limits.edit')->name('risk.velocity.store');
    Route::post('risk/velocity/{rule}/toggle', [VelocityController::class, 'toggle'])->middleware('permission:merchants.limits.edit')->name('risk.velocity.toggle');
    Route::get('alerts', [AlertController::class, 'index'])->middleware('permission:payments.view')->name('alerts.index');
    Route::post('alerts/settings', [AlertController::class, 'settings'])->middleware('permission:payments.refund')->name('alerts.settings');
    Route::post('alerts/manual', [AlertController::class, 'manual'])->middleware('permission:payments.refund')->name('alerts.manual');
    Route::post('alerts/client', [AlertController::class, 'createClient'])->middleware('permission:settings.security.manage')->name('alerts.client');
    Route::get('disputes-analytics', [DisputeAnalyticsController::class, 'index'])->middleware('permission:payments.view')->name('disputes.analytics');
    Route::post('disputes/{dispute}/evidence', [DisputeEvidenceController::class, 'store'])->middleware('permission:payments.refund')->name('disputes.evidence');

    // MID management, BIN routing and platform fees (round 6).
    Route::get('mids', [MidControlController::class, 'index'])->middleware('permission:routing.view')->name('mids.index');
    Route::post('mids/{mid}', [MidControlController::class, 'update'])->middleware('permission:merchants.limits.edit')->name('mids.update');
    Route::get('bin-routing', [BinRoutingController::class, 'index'])->middleware('permission:routing.view')->name('bins.index');
    Route::post('bin-routing', [BinRoutingController::class, 'store'])->middleware('permission:routing.draft')->name('bins.store');
    Route::post('bin-routing/import', [BinRoutingController::class, 'import'])->middleware('permission:routing.draft')->name('bins.import');
    Route::post('bin-routing/{pref}/toggle', [BinRoutingController::class, 'toggle'])->middleware('permission:routing.draft')->name('bins.toggle');
    Route::get('platform-fees', [PlatformFeeController::class, 'index'])->middleware('permission:fees.view')->name('platform-fees.index');
    Route::post('platform-fees', [PlatformFeeController::class, 'store'])->middleware('permission:fees.manage')->name('platform-fees.store');
    Route::post('platform-fees/{rule}/toggle', [PlatformFeeController::class, 'toggle'])->middleware('permission:fees.manage')->name('platform-fees.toggle');

    Route::get('statements', [StatementController::class, 'index'])->middleware('permission:fees.view')->name('statements.index');
    Route::post('statements/generate', [StatementController::class, 'generate'])->middleware('permission:fees.manage')->name('statements.generate');
    Route::post('statements/{statement}/submit', [StatementController::class, 'submit'])->middleware('permission:fees.manage')->name('statements.submit');
    Route::get('statements/{statement}/download/{format}', [StatementController::class, 'download'])->middleware('permission:fees.view')->name('statements.download');

    Route::get('routing', [RoutingController::class, 'index'])->middleware('permission:routing.view')->name('routing');
    Route::post('routing/draft', [RoutingController::class, 'draft'])->middleware('permission:routing.draft')->name('routing.draft');
    Route::post('routing/{ruleSet}/submit', [RoutingController::class, 'submit'])->middleware('permission:routing.draft')->name('routing.submit');

    Route::get('management', [ManagementController::class, 'index'])->middleware('permission:audit.view')->name('management');
    Route::post('management/users', [ManagementController::class, 'invite'])->middleware('permission:users.manage')->name('management.invite');

    Route::get('developers', [DevelopersController::class, 'index'])->middleware('permission:merchants.view')->name('developers');
    Route::post('developers/flags/{flag}', [DevelopersController::class, 'flag'])->middleware('permission:settings.security.manage')->name('developers.flag');
});

// ----- Merchant portal -----
$merchant = Route::prefix('app')->name('merchant.')->middleware(['auth', 'portal:merchant']);
if (is_string($host = config('amplirail.hosts.merchant')) && $host !== '') {
    $merchant->domain($host);
}
$merchant->group(function (): void {
    Route::controller(MerchantPortalController::class)->group(function (): void {
        Route::get('/', 'dashboard')->middleware('permission:payments.view')->name('dashboard');
        Route::post('environment', 'environment')->name('environment');
        Route::get('transactions', 'transactions')->middleware('permission:payments.view')->name('transactions.index');
        Route::get('transactions/{transaction}', 'transaction')->middleware('permission:payments.view')->name('transactions.show');
        Route::post('transactions/{transaction}/refund', 'refund')->middleware('permission:payments.refund')->name('transactions.refund');
        Route::post('transactions/{transaction}/void', 'void')->middleware('permission:payments.void')->name('transactions.void');
        Route::post('step-up', 'sendStepUp')->middleware('throttle:otp')->name('step-up');
        Route::get('terminal', 'terminal')->middleware('permission:payments.refund')->name('terminal');
        Route::post('terminal', 'charge')->middleware('permission:payments.refund')->name('terminal.charge');
        Route::get('payment-requests', 'links')->middleware('permission:payments.refund')->name('links.index');
        Route::post('payment-requests', 'createLink')->middleware('permission:payments.refund')->name('links.store');
        Route::get('statements', 'statements')->middleware('permission:fees.view')->name('statements.index');
        Route::get('statements/{statement}/download/{format}', 'downloadStatement')->middleware('permission:fees.view')->name('statements.download');
        Route::get('documents', 'documents')->middleware('permission:documents.view')->name('documents.index');
        Route::post('documents', 'uploadDocument')->middleware('permission:documents.manage')->name('documents.upload');
        Route::get('developers', 'developers')->middleware('permission:api_keys.manage')->name('developers');
        Route::post('developers/keys/{key}/reveal', 'revealKey')->middleware('permission:api_keys.manage')->name('keys.reveal');
        Route::post('developers/keys/{key}/roll', 'rollKey')->middleware('permission:api_keys.manage')->name('keys.roll');
        Route::post('developers/webhooks', 'createWebhook')->middleware('permission:webhooks.manage')->name('webhooks.store');
        Route::post('developers/webhooks/{endpoint}/test', 'testWebhook')->middleware('permission:webhooks.manage')->name('webhooks.test');
    });
    Route::get('disputes', [MerchantDisputeController::class, 'index'])->middleware('permission:payments.view')->name('disputes.index');
    Route::get('disputes/{dispute}', [MerchantDisputeController::class, 'show'])->middleware('permission:payments.view')->name('disputes.show');
    Route::post('disputes/{dispute}/respond', [MerchantDisputeController::class, 'respond'])->middleware('permission:payments.refund')->name('disputes.respond');
    Route::post('disputes/{dispute}/accept', [MerchantDisputeController::class, 'accept'])->middleware('permission:payments.refund')->name('disputes.accept');
    Route::post('disputes/{dispute}/evidence', [DisputeEvidenceController::class, 'store'])->middleware('permission:payments.refund')->name('disputes.evidence');
    Route::get('alerts', [MerchantAlertController::class, 'index'])->middleware('permission:payments.view')->name('alerts.index');
    Route::post('alerts/settings', [MerchantAlertController::class, 'settings'])->middleware('permission:payments.refund')->name('alerts.settings');
    Route::get('documents/{document}/download', [DocumentDownloadController::class, 'merchant'])->middleware(['signed', 'permission:documents.view'])->name('documents.download');
});

// ----- Hosted pay page (payment links) -----
$pay = Route::middleware('throttle:pay');
if (is_string($host = config('amplirail.hosts.pay')) && $host !== '') {
    $pay->domain($host);
}
$pay->group(function (): void {
    Route::get('l/{token}', [PayController::class, 'show'])->where('token', '[A-Za-z0-9_\-.]+')->name('pay.show');
    Route::post('l/{token}', [PayController::class, 'pay'])->where('token', '[A-Za-z0-9_\-.]+')->name('pay.submit');
});
