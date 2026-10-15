<?php

declare(strict_types=1);

namespace Connectors\Boarding;

final class SimulatorBoarding implements MerchantBoarding
{
    public function submitApplication(string $route, array $package): BoardingResult
    {
        $ref = 'brd_sim_'.substr(hash('sha256', $route.'|'.($package['merchant_id'] ?? '')), 0, 12);

        return new BoardingResult('submitted', $ref, "Application package recorded for route $route (simulator).");
    }
}
