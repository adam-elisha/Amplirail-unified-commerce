<?php

declare(strict_types=1);

namespace Connectors\Risk;

final class RiskAssessment
{
    /**
     * @param  list<array{code:string,description:string,evidence:string}>  $signals
     * @param  array<string,int|string|bool>  $recommendedControls  reserve_bps, max_ticket_minor, funding_delay_days, ...
     */
    public function __construct(
        public readonly string $reference,
        public readonly int $score,              // 0-100
        public readonly string $band,            // green | yellow | red
        public readonly int $confidence,         // 0-100
        public readonly string $recommendation,  // approve | review | decline
        public readonly array $signals,
        public readonly array $recommendedControls,
        public readonly bool $gateComplete,      // pre-submission gate status
        public readonly string $provider,
    ) {}
}
