<?php

declare(strict_types=1);

namespace Modules\Payments\Domain;

/** Canonical decline categories (master document 7). Each connector's raw codes map here. */
final class DeclineCategory
{
    public static function fromCode(?string $code, string $outcome): ?string
    {
        if ($outcome === 'approved') {
            return null;
        }

        return match ($code) {
            '05', '200', '201' => 'do_not_honor',
            '51', '202', '203' => 'insufficient_funds',
            '59', '250', '251' => 'fraud_suspected',
            '1A', '460' => 'authentication_required',
            '14', '220', '221' => 'invalid_card',
            'timeout' => 'timeout',
            'provider_unavailable', 'network' => 'provider_unavailable',
            default => $outcome === 'declined' ? 'do_not_honor' : 'provider_unavailable',
        };
    }
}
