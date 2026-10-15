<?php

declare(strict_types=1);

namespace App\Providers;

use AmpliRail\Http\HttpClient;
use AmpliRail\Links\PaymentLinks;
use App\Support\Crypto\FieldEncryption;
use App\Support\RequestContext;
use Aws\SecretsManager\SecretsManagerClient;
use Connectors\Boarding\MerchantBoarding;
use Connectors\Boarding\SimulatorBoarding;
use Connectors\ConnectorRegistry;
use Connectors\Http\LaravelHttpClient;
use Connectors\Kyb\KybProvider;
use Connectors\Kyb\KybSimulator;
use Connectors\Risk\RiskDecision;
use Connectors\Risk\SimulatorRiskDecision;
use Connectors\Secrets\ArraySecretsResolver;
use Connectors\Secrets\AwsSecretsResolver;
use Connectors\Secrets\EnvSecretsResolver;
use Connectors\Secrets\SecretsResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Modules\Approvals\Application\ApprovalService;
use Modules\Approvals\Contracts\ApprovalRequests;
use Modules\Audit\Contracts\AuditTrail;
use Modules\Audit\Infrastructure\DatabaseAuditTrail;
use Modules\Disputes\Application\DisputeReportService;
use Modules\Disputes\Contracts\DisputeReports;
use Modules\Events\Contracts\EventLog;
use Modules\Events\Contracts\EventPublisher;
use Modules\Events\Contracts\Outbox;
use Modules\Events\Infrastructure\DatabaseOutbox;
use Modules\Events\Infrastructure\LogPublisher;
use Modules\Events\Infrastructure\NullPublisher;
use Modules\Events\Infrastructure\RedisStreamPublisher;
use Modules\Identity\Application\ApiKeyService;
use Modules\Identity\Application\UserAdministration;
use Modules\Identity\Contracts\AccessControl;
use Modules\Identity\Contracts\ApiKeyIssuing;
use Modules\Identity\Contracts\StepUpVerifier;
use Modules\Identity\Contracts\UserDirectory;
use Modules\Ledger\Application\LedgerService;
use Modules\Ledger\Contracts\Ledger;
use Modules\Onboarding\Application\EloquentMerchantDirectory;
use Modules\Onboarding\Application\GoLiveApprovalHandler;
use Modules\Onboarding\Application\GoLiveGate;
use Modules\Onboarding\Application\MerchantLifecycle;
use Modules\Onboarding\Application\OnboardingAutomation;
use Modules\Onboarding\Contracts\MerchantDirectory;
use Modules\Payments\Application\PaymentOrchestrator;
use Modules\Payments\Application\PaymentReportService;
use Modules\Payments\Contracts\PaymentGateway;
use Modules\Payments\Contracts\PaymentReports;
use Modules\Routing\Application\EligibilityService;
use Modules\Routing\Application\MerchantAccountService;
use Modules\Routing\Contracts\Eligibility;
use Modules\Routing\Contracts\MerchantAccounts;
use Modules\Routing\Infrastructure\MerchantAccount;
use Modules\Reserves\Application\ReserveService;
use Modules\Settlement\Application\StatementService;
use Modules\Tenancy\Contracts\OrgDirectory;
use Modules\Tenancy\Contracts\TenantScope;
use Modules\Tenancy\Infrastructure\EloquentOrgDirectory;
use Modules\Tenancy\Infrastructure\TenantManager;
use Modules\Webhooks\Application\WebhookService;
use Modules\Webhooks\Application\WebhookUrlPolicy;
use Modules\Webhooks\Contracts\WebhookStatus;

/**
 * Binds module contracts to implementations. Request-aware services are "scoped": Octane and queue workers get a fresh
 * instance per request/job, so tenant context can never leak between merchants.
 */
final class AmpliRailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(RequestContext::class);
        $this->app->scoped(\App\Support\Branding\BrandResolver::class); // ApplyBrand sets the brand that SignInController reads
        $this->app->scoped(TenantScope::class, TenantManager::class);
        $this->app->scoped(OrgDirectory::class, EloquentOrgDirectory::class);
        $this->app->scoped(AuditTrail::class, DatabaseAuditTrail::class);
        $this->app->scoped(DatabaseOutbox::class);
        $this->app->scoped(Outbox::class, DatabaseOutbox::class);
        $this->app->scoped(EventLog::class, DatabaseOutbox::class);
        $this->app->singleton(EventPublisher::class, fn () => match (config('amplirail.outbox.publisher')) {
            'redis_streams' => new RedisStreamPublisher((string) config('amplirail.outbox.stream')),
            'null' => new NullPublisher,
            default => new LogPublisher,
        });

        // Secrets and encryption.
        $this->app->singleton(SecretsResolver::class, function (Application $app): SecretsResolver {
            return match (config('amplirail.secrets.driver')) {
                'aws' => new AwsSecretsResolver(
                    new SecretsManagerClient(['version' => '2017-10-17', 'region' => config('amplirail.secrets.region')]),
                    (string) config('amplirail.secrets.prefix'),
                    (int) config('amplirail.secrets.cache_seconds'),
                ),
                'array' => new ArraySecretsResolver,
                default => new EnvSecretsResolver($app->environment('production')),
            };
        });
        $this->app->singleton(FieldEncryption::class, fn (Application $app) => new FieldEncryption(
            $app->make(SecretsResolver::class),
            (string) config('amplirail.secrets.refs.field_encryption_key'),
            (string) config('amplirail.secrets.refs.fingerprint_key'),
        ));
        $this->app->scoped(PaymentLinks::class, fn (Application $app) => new PaymentLinks(
            $app->make(SecretsResolver::class)->get((string) config('amplirail.secrets.refs.link_signing_key')),
        ));

        // Connectors.
        $this->app->singleton(HttpClient::class, fn (Application $app) => new LaravelHttpClient(
            $app->make('cache.store'),
            (int) config('connectors.circuit_breaker.failure_threshold', 5),
            (int) config('connectors.circuit_breaker.open_seconds', 30),
        ));
        $this->app->scoped(ConnectorRegistry::class, fn (Application $app) => new ConnectorRegistry(
            (array) config('connectors'),
            $app->make(HttpClient::class),
            $app->make(SecretsResolver::class),
            fn (string $maccId): string => (string) (MerchantAccount::query()->whereKey($maccId)->value('credential_ref')
                ?? throw new \RuntimeException("No credential reference for $maccId")),
            fn (string $maccId): string => (string) (MerchantAccount::query()->whereKey($maccId)->value('provider_mid')
                ?? throw new \RuntimeException("No provider MID for $maccId")),
        ));
        $this->app->singleton(KybProvider::class, KybSimulator::class);
        $this->app->singleton(RiskDecision::class, SimulatorRiskDecision::class);
        $this->app->singleton(MerchantBoarding::class, SimulatorBoarding::class);

        // Module contracts.
        $this->app->scoped(ApiKeyIssuing::class, ApiKeyService::class);
        $this->app->scoped(UserAdministration::class);
        $this->app->scoped(AccessControl::class, UserAdministration::class);
        $this->app->scoped(StepUpVerifier::class, UserAdministration::class);
        $this->app->scoped(UserDirectory::class, UserAdministration::class);
        $this->app->scoped(ApprovalService::class);
        $this->app->scoped(ApprovalRequests::class, ApprovalService::class);
        $this->app->scoped(MerchantDirectory::class, EloquentMerchantDirectory::class);
        $this->app->scoped(MerchantAccounts::class, MerchantAccountService::class);
        $this->app->scoped(EligibilityService::class);
        $this->app->scoped(Eligibility::class, EligibilityService::class);
        $this->app->scoped(Ledger::class, LedgerService::class);
        $this->app->scoped(PaymentGateway::class, PaymentOrchestrator::class);
        $this->app->scoped(PaymentReports::class, PaymentReportService::class);
        $this->app->scoped(WebhookService::class);
        $this->app->scoped(WebhookStatus::class, WebhookService::class);
        $this->app->singleton(WebhookUrlPolicy::class, fn (Application $app) => new WebhookUrlPolicy(
            (bool) config('amplirail.webhooks.allow_insecure_targets') && ! $app->environment('production'),
        ));
        $this->app->scoped(StatementService::class);
        $this->app->scoped(DisputeReports::class, DisputeReportService::class);
        $this->app->scoped(ReserveService::class);

        // Internal event consumers and approval handlers.
        $this->app->tag([WebhookService::class, OnboardingAutomation::class], 'amplirail.event_consumers');
        $this->app->bind('amplirail.handler.go_live', fn (Application $app) => new GoLiveApprovalHandler('go_live',
            $app->make(GoLiveGate::class), $app->make(MerchantLifecycle::class), $app->make(ApiKeyIssuing::class)));
        $this->app->bind('amplirail.handler.go_live_override', fn (Application $app) => new GoLiveApprovalHandler('go_live_override',
            $app->make(GoLiveGate::class), $app->make(MerchantLifecycle::class), $app->make(ApiKeyIssuing::class)));
        $this->app->tag([EligibilityService::class, StatementService::class, ReserveService::class, 'amplirail.handler.go_live', 'amplirail.handler.go_live_override'],
            'amplirail.approval_handlers');
    }
}
