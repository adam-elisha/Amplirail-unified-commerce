<?php

declare(strict_types=1);

namespace Modules\Onboarding\Application;

use App\Support\Actor;
use App\Support\ApiException;
use App\Support\Ids;
use Connectors\Risk\RiskDecision;
use Connectors\Risk\RiskInput;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Contracts\AuditTrail;
use Modules\Events\Contracts\Outbox;
use Modules\Identity\Contracts\StepUpVerifier;
use Modules\Onboarding\Infrastructure\Merchant;
use Modules\Onboarding\Infrastructure\MerchantDocument;
use Modules\Onboarding\Infrastructure\RiskCase;

/**
 * Day 1 underwriting. RoboRiskAnalysis is reached ONLY through the RiskDecision connector (ADR-0004). Its output is a
 * recommendation; an authorized human (risk.decide + step-up) records the decision. Controls are applied only when the
 * human explicitly accepts them.
 */
final class RiskReview
{
    public function __construct(
        private readonly RiskDecision $risk,
        private readonly MerchantLifecycle $lifecycle,
        private readonly StepUpVerifier $stepUp,
        private readonly AuditTrail $audit,
        private readonly Outbox $outbox,
    ) {}

    public function requestAssessment(Merchant $m): RiskCase
    {
        $input = new RiskInput(
            merchantId: $m->id,
            legalName: $m->legal_name,
            mcc: $m->mcc,
            country: $m->country,
            monthlyVolumeMinor: $m->monthly_volume_minor,
            maxTicketMinor: $m->max_ticket_minor,
            currency: $m->currency,
            channels: $m->channels,
            website: $m->website,
            documentIds: array_values(MerchantDocument::query()->where('merchant_id', $m->id)->where('status', 'active')->get()->map(fn (MerchantDocument $d): string => $d->id)->all()),
        );
        $a = $this->risk->underwrite($input);

        return DB::transaction(function () use ($m, $a): RiskCase {
            $case = RiskCase::query()->create([
                'id' => Ids::new('rsk'), 'merchant_id' => $m->id, 'provider' => $a->provider, 'reference' => $a->reference,
                'score' => $a->score, 'band' => $a->band, 'confidence' => $a->confidence, 'recommendation' => $a->recommendation,
                'signals' => $a->signals, 'recommended_controls' => $a->recommendedControls, 'gate_complete' => $a->gateComplete, 'status' => 'open',
            ]);
            $this->outbox->record('risk.assessment.completed', 'merchant', $m->id, ['merchant_id' => $m->id, 'risk_case_id' => $case->id,
                'band' => $a->band, 'score' => $a->score, 'recommendation' => $a->recommendation], orgPath: $m->org_path);

            return $case;
        });
    }

    public function latest(Merchant $m): ?RiskCase
    {
        return RiskCase::query()->where('merchant_id', $m->id)->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    /** decision: approve | decline | manual_review */
    public function decide(Merchant $m, string $decision, string $reason, string $userId, string $stepUpCode, bool $applyRecommendedControls, Actor $actor): void
    {
        if (! in_array($decision, ['approve', 'decline', 'manual_review'], true)) {
            throw ApiException::invalid('invalid_decision', 'Decision must be approve, decline or manual_review.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw ApiException::invalid('reason_required', 'A reason is required for every underwriting decision.');
        }
        if (! $this->stepUp->verify($userId, $stepUpCode)) {
            throw ApiException::forbidden('Step-up verification failed.');
        }
        $case = $this->latest($m);
        if ($decision === 'approve' && $case !== null && $case->band === 'red' && ! $applyRecommendedControls) {
            throw ApiException::invalid('red_requires_controls', 'Red-band cases can only be approved with the recommended controls (never auto-approved).');
        }

        DB::transaction(function () use ($m, $decision, $reason, $userId, $applyRecommendedControls, $case, $actor): void {
            if ($case !== null) {
                $case->forceFill(['status' => 'decided', 'decision' => $decision, 'decided_by' => $userId, 'decided_at' => now(), 'decision_reason' => $reason])->save();
            }
            if ($decision === 'approve') {
                $changes = ['risk_tier' => $case->band ?? 'green'];
                if ($applyRecommendedControls && $case !== null) {
                    $changes['risk_profile'] = array_merge($m->risk_profile ?? [], $case->recommended_controls);
                }
                $m->forceFill($changes)->save();
                $this->audit->record('risk.decision', 'merchant', $m->id, null, ['decision' => $decision, 'controls_applied' => $applyRecommendedControls, 'risk_case_id' => $case?->id], $actor);
            }
            $event = match ($decision) {
                'approve' => 'risk.approved', 'decline' => 'risk.declined', default => 'risk.manual_review'
            };
            $this->lifecycle->apply($m->refresh(), $event, ['risk_case_id' => $case?->id, 'decided_by' => $userId], $reason, null, $actor);
        });
    }
}
