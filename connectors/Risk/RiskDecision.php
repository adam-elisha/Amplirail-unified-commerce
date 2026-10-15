<?php

declare(strict_types=1);

namespace Connectors\Risk;

/**
 * RiskDecision connector (master document 10.2, ADR-0004). The ONLY way AmpliRail reaches RoboRiskAnalysis, which is a
 * separate Laravel product with its own repository, AWS account and database. No RoboRisk scoring logic lives in
 * AmpliRail. Never send PAN, CVV or full bank numbers: inputs are canonical, tokenized merchant data only.
 * Recommendations are advisory; authorized humans decide (risk.decide), and controls go through Approvals.
 */
interface RiskDecision
{
    public function underwrite(RiskInput $input): RiskAssessment;

    public function healthy(): bool;
}
