<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Raised for expected API failures. Rendered as the single error shape (master document 13):
 * {"error": {"type", "code", "message", "decline_category", "request_id"}}
 */
final class ApiException extends RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        public readonly int $status,
        public readonly string $type,
        public readonly string $errorCode,
        string $message,
        public readonly ?string $declineCategory = null,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string,mixed> $details */
    public static function invalid(string $code, string $message, array $details = []): self
    {
        return new self(422, 'invalid_request_error', $code, $message, null, $details);
    }

    public static function notFound(string $what = 'resource'): self
    {
        return new self(404, 'invalid_request_error', 'resource_missing', "No such $what.");
    }

    public static function forbidden(string $message = 'You do not have permission for this action.'): self
    {
        return new self(403, 'permission_error', 'forbidden', $message);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, 'idempotency_error', $code, $message);
    }

    public static function featureDisabled(string $flag): self
    {
        return new self(403, 'invalid_request_error', 'feature_disabled', "This capability is not enabled ($flag).");
    }
}
