<?php

declare(strict_types=1);

namespace Connectors\Risk;

/**
 * Deterministic stand-in until the RoboRiskAnalysis API is available. Bands follow simple, documented thresholds so
 * tests and demos are repeatable; this is NOT RoboRisk scoring logic.
 */
final class SimulatorRiskDecision implements RiskDecision
{
    /** MCCs treated as restricted by the simulator (gambling, crypto, adult, firearms, pharma, ticket resale). */
    private const RESTRICTED = ['7995', '6051', '5967', '5993', '5122', '7922', '4829'];

    public function underwrite(RiskInput $input): RiskAssessment
    {
        $signals = [];
        $score = 20;
        if (in_array($input->mcc, self::RESTRICTED, true)) {
            $score += 55;
            $signals[] = ['code' => 'restricted_mcc', 'description' => "MCC {$input->mcc} is restricted", 'evidence' => 'application.mcc'];
        }
        if ($input->monthlyVolumeMinor > 50_000_000) {
            $score += 15;
            $signals[] = ['code' => 'high_volume', 'description' => 'Expected monthly volume above 500,000.00', 'evidence' => 'application.monthly_volume'];
        }
        if ($input->website === null || $input->website === '') {
            $score += 10;
            $signals[] = ['code' => 'no_website', 'description' => 'No website supplied', 'evidence' => 'application.website'];
        }
        $score = min(100, $score);
        $band = $score >= 70 ? 'red' : ($score >= 40 ? 'yellow' : 'green');

        return new RiskAssessment(
            reference: 'rra_sim_'.substr(hash('sha256', $input->merchantId), 0, 12),
            score: $score,
            band: $band,
            confidence: 80,
            recommendation: match ($band) {
                'green' => 'approve', 'yellow' => 'review', default => 'decline'
            },
            signals: $signals,
            recommendedControls: [
                'reserve_bps' => $band === 'green' ? 0 : ($band === 'yellow' ? 500 : 1000),
                'max_ticket_minor' => $input->maxTicketMinor > 0 ? $input->maxTicketMinor : 500000,
                'funding_delay_days' => $band === 'green' ? 2 : 7,
            ],
            gateComplete: true,
            provider: 'risk_simulator',
        );
    }

    public function healthy(): bool
    {
        return true;
    }
}
