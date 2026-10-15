<?php

declare(strict_types=1);

namespace Modules\Identity\Contracts;

/** The authenticated machine caller: a merchant secret key or an internal service client. */
final class ApiPrincipal
{
    /** @param list<string> $scopes */
    public function __construct(
        public readonly string $kind,            // api_key | service
        public readonly string $id,              // key ID or service client ID
        public readonly string $tenantId,
        public readonly ?string $merchantId,
        public readonly ?string $environment,    // sandbox | live (api keys)
        public readonly array $scopes,
    ) {}

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true) || in_array('*', $this->scopes, true);
    }
}
