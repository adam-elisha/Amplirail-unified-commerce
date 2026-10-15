<?php

declare(strict_types=1);

namespace Connectors\Boarding;

/**
 * MerchantBoarding (master document 7). Processor boarding API integrations are a later sprint; at launch the
 * simulator returns a reference and operations record the processor's answer (approval, MID/TID) in the admin console.
 */
interface MerchantBoarding
{
    /** @param array<string,mixed> $package canonical, tokenized application package */
    public function submitApplication(string $route, array $package): BoardingResult;
}
