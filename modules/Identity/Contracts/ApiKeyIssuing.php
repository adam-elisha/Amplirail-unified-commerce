<?php

declare(strict_types=1);

namespace Modules\Identity\Contracts;

interface ApiKeyIssuing
{
    /**
     * Issues a publishable + secret key pair (arpub_<env>_ / arsec_<env>_). The secret is stored hashed and held
     * encrypted for a single reveal in the merchant portal. Idempotent per merchant and environment: when an active
     * pair exists it is returned instead of a new one (a retry never creates a second key).
     */
    public function issuePair(string $tenantId, string $merchantId, string $environment, ?string $createdBy = null): IssuedKeyPair;
}
