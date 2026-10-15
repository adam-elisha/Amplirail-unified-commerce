<?php

declare(strict_types=1);

namespace Connectors;

use AmpliRail\Connectors\Contracts\PaymentConnector;
use AmpliRail\Connectors\Gattera\GatteraBridgeConnector;
use AmpliRail\Connectors\Nmi\NmiBridgeConnector;
use AmpliRail\Http\HttpClient;
use Connectors\Secrets\SecretsResolver;
use Connectors\Simulator\ScenarioSimulator;

/**
 * Builds the tested core connectors with their runtime dependencies. Provider credentials are looked up per
 * MerchantAccount through the credential resolver (a closure over the secrets manager); they are never cached in code.
 */
final class ConnectorRegistry
{
    /** @var array<string,PaymentConnector> */
    private array $built = [];

    /**
     * @param  array<string,mixed>  $manifests  config/connectors.php
     * @param  \Closure(string $merchantAccountId): string  $credentialRefFor
     * @param  \Closure(string $merchantAccountId): string  $providerMidFor
     */
    public function __construct(
        private array $manifests,
        private readonly HttpClient $http,
        private readonly SecretsResolver $secrets,
        private readonly \Closure $credentialRefFor,
        private readonly \Closure $providerMidFor,
    ) {}

    public function get(string $code): PaymentConnector
    {
        if (! $this->isEnabled($code)) {
            throw new \RuntimeException("Connector $code is not enabled.");
        }

        return $this->built[$code] ??= match ($code) {
            'simulator' => new ScenarioSimulator,
            'nmi_bridge' => new NmiBridgeConnector(
                $this->http,
                (string) $this->manifest($code)['endpoint'],
                fn (string $maccId): string => $this->secrets->get(($this->credentialRefFor)($maccId)),
                (int) ($this->manifest($code)['timeout_ms'] ?? 8000),
            ),
            'gattera_bridge' => new GatteraBridgeConnector(
                $this->http,
                (string) $this->manifest($code)['base_url'],
                fn (string $maccId): string => $this->secrets->get(($this->credentialRefFor)($maccId)),
                $this->providerMidFor,
                timeoutMs: (int) ($this->manifest($code)['timeout_ms'] ?? 8000),
            ),
            default => throw new \RuntimeException("Unknown connector $code"),
        };
    }

    /** Lets tests register a fake connector under a code (e.g. one that is down). */
    public function register(string $code, PaymentConnector $connector): void
    {
        $this->built[$code] = $connector;
        $this->manifests[$code] ??= ['name' => $code, 'enabled' => true, 'environments' => ['sandbox', 'live'], 'currencies' => [], 'channels' => [], 'operations' => []];
    }

    public function isEnabled(string $code): bool
    {
        return (bool) ($this->manifests[$code]['enabled'] ?? false);
    }

    /** @return array<string,mixed> */
    public function manifest(string $code): array
    {
        $m = $this->manifests[$code] ?? null;
        if (! is_array($m)) {
            throw new \RuntimeException("Unknown connector $code");
        }

        return $m;
    }

    /** @return array<string,array<string,mixed>> */
    public function manifests(): array
    {
        return array_filter($this->manifests, fn ($m, $k) => is_array($m) && $k !== 'circuit_breaker', ARRAY_FILTER_USE_BOTH);
    }

    public function supportsEnvironment(string $code, string $environment): bool
    {
        return in_array($environment, (array) ($this->manifests[$code]['environments'] ?? []), true);
    }
}
