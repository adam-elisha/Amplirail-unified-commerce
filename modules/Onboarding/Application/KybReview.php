<?php

declare(strict_types=1);

namespace Modules\Onboarding\Application;

use App\Support\Actor;
use App\Support\Ids;
use Connectors\Kyb\KybProvider;
use Illuminate\Support\Facades\DB;
use Modules\Onboarding\Infrastructure\KybCheck;
use Modules\Onboarding\Infrastructure\Merchant;

/** Day 1 KYB/KYC through the KYBProvider connector. Anything other than a clean pass goes to a human (manual_review). */
final class KybReview
{
    public function __construct(
        private readonly KybProvider $kyb,
        private readonly MerchantLifecycle $lifecycle,
    ) {}

    public function run(Merchant $m, ?Actor $actor = null): KybCheck
    {
        if ($m->state !== 'kyb_pending') {
            throw new \DomainException('KYB runs only in kyb_pending.');
        }
        $result = $this->kyb->verifyBusiness([
            'merchant_id' => $m->id, 'legal_name' => $m->legal_name, 'ein_fingerprint' => $m->ein_fingerprint,
            'country' => $m->country, 'region' => $m->region,
        ]);

        return DB::transaction(function () use ($m, $result, $actor): KybCheck {
            $check = KybCheck::query()->create([
                'id' => Ids::new('kyb'), 'merchant_id' => $m->id, 'provider' => $result->provider, 'reference' => $result->reference,
                'status' => $result->status, 'reasons' => $result->reasons, 'created_at' => now(),
            ]);
            $m->forceFill(['kyb_status' => $result->status])->save();
            $event = $result->status === 'passed' ? 'kyb.passed' : 'kyb.review_required';
            $this->lifecycle->apply($m->refresh(), $event, ['kyb_check_id' => $check->id, 'status' => $result->status], implode('; ', $result->reasons) ?: null, null, $actor);

            return $check;
        });
    }

    /** Human resolution of a manual KYB review (operations / risk). */
    public function resolveManually(Merchant $m, string $reason, Actor $actor): void
    {
        DB::transaction(function () use ($m, $reason, $actor): void {
            $m->forceFill(['kyb_status' => 'passed'])->save();
            $this->lifecycle->apply($m->refresh(), 'kyb.passed', ['resolved_by' => $actor->id, 'manual' => true], $reason, null, $actor);
        });
    }
}
