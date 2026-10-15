<?php

declare(strict_types=1);

namespace Modules\Identity\Contracts;

interface UserDirectory
{
    /**
     * Creates (or reuses) the merchant's primary portal user with the merchant_owner role and emails a set-password
     * invitation. Idempotent per email and merchant. Returns the user ID.
     */
    public function inviteMerchantOwner(string $tenantId, string $merchantId, string $orgPath, string $email, string $name): string;
}
