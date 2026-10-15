<?php
declare(strict_types=1);
namespace AmpliRail\Connectors\Contracts;

/**
 * The only way AmpliRail talks to a provider (rule 1).
 * Every implementation must pass tests/run.php contract checks against the simulator scenarios.
 */
interface PaymentConnector
{
    public function code(): string;                 // e.g. "simulator", "nmi_bridge", "gattera_bridge"
    public function sale(PaymentRequest $r): ConnectorResult;
    public function authorize(PaymentRequest $r): ConnectorResult;
    public function capture(PaymentRequest $r): ConnectorResult;
    public function refund(PaymentRequest $r): ConnectorResult;
    public function void(PaymentRequest $r): ConnectorResult;
}
