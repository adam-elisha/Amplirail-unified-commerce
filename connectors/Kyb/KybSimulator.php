<?php

declare(strict_types=1);

namespace Connectors\Kyb;

/**
 * Deterministic KYB simulator. Legal names containing "KYBREVIEW" return review, "KYBFAIL" return failed;
 * everything else passes.
 */
final class KybSimulator implements KybProvider
{
    public function verifyBusiness(array $business): KybResult
    {
        $name = strtoupper((string) ($business['legal_name'] ?? ''));
        $ref = 'kyb_sim_'.substr(hash('sha256', $name.'|'.($business['merchant_id'] ?? '')), 0, 12);

        return match (true) {
            str_contains($name, 'KYBFAIL') => new KybResult('failed', ['Business registry: no matching entity'], 'kyb_simulator', $ref),
            str_contains($name, 'KYBREVIEW') => new KybResult('review', ['Address does not match registry record'], 'kyb_simulator', $ref),
            default => new KybResult('passed', [], 'kyb_simulator', $ref),
        };
    }
}
