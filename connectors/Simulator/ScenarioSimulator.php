<?php

declare(strict_types=1);

namespace Connectors\Simulator;

use AmpliRail\Connectors\Contracts\ConnectorResult;
use AmpliRail\Connectors\Contracts\PaymentConnector;
use AmpliRail\Connectors\Contracts\PaymentRequest;
use AmpliRail\Connectors\Simulator\SimulatorConnector;

/**
 * Adds the master document 7.1 flag scenarios on top of the tested core simulator (amount endings stay in the core):
 *   metadata sim = sim_timeout  -> timeout, unknown state, retryable
 *   metadata sim = sim_outage   -> provider_unavailable, retryable
 *   metadata sim = sim_avs_fail -> approved with AVS mismatch
 */
final class ScenarioSimulator implements PaymentConnector
{
    public function __construct(private readonly SimulatorConnector $core = new SimulatorConnector) {}

    public function code(): string
    {
        return 'simulator';
    }

    public function sale(PaymentRequest $r): ConnectorResult
    {
        return $this->flagged($r) ?? $this->core->sale($r);
    }

    public function authorize(PaymentRequest $r): ConnectorResult
    {
        return $this->flagged($r) ?? $this->core->authorize($r);
    }

    public function capture(PaymentRequest $r): ConnectorResult
    {
        return $this->core->capture($r);
    }

    public function refund(PaymentRequest $r): ConnectorResult
    {
        return $this->core->refund($r);
    }

    public function void(PaymentRequest $r): ConnectorResult
    {
        return $this->core->void($r);
    }

    private function flagged(PaymentRequest $r): ?ConnectorResult
    {
        return match ($r->metadata['sim'] ?? null) {
            'sim_timeout' => new ConnectorResult(ConnectorResult::ERROR, null, null, 'timeout', 'Provider timeout', retryableError: true),
            'sim_outage' => new ConnectorResult(ConnectorResult::ERROR, null, null, 'provider_unavailable', 'Provider unavailable', retryableError: true),
            'sim_avs_fail' => ($res = $this->core->sale($r))->approved()
                ? new ConnectorResult(ConnectorResult::APPROVED, $res->providerTransactionId, $res->authCode, '00', 'Approved (AVS mismatch)', raw: ['avs' => 'N'])
                : $res,
            default => null,
        };
    }
}
