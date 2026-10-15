<?php

declare(strict_types=1);

namespace Connectors\Kyb;

/** KYBProvider (master document 7). Real vendors are added behind this interface; launch uses the simulator. */
interface KybProvider
{
    /** @param array<string,mixed> $business canonical business data (legal name, EIN fingerprint, address) */
    public function verifyBusiness(array $business): KybResult;
}
