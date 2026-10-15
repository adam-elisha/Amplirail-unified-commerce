<?php
declare(strict_types=1);
namespace AmpliRail\Connectors\Contracts;

final class ConnectorResult
{
    public const APPROVED = 'approved';
    public const DECLINED = 'declined';
    public const ERROR = 'error';

    public function __construct(
        public readonly string $outcome,            // approved | declined | error
        public readonly ?string $providerTransactionId,
        public readonly ?string $authCode,
        public readonly ?string $responseCode,      // normalized issuer or provider code
        public readonly string $message,
        public readonly bool $softDecline = false,  // retry may succeed
        public readonly bool $retryableError = false, // safe to retry (no provider record created)
        public readonly array $raw = [],            // redacted provider response for audit
    ) {}

    public function approved(): bool { return $this->outcome === self::APPROVED; }
}
