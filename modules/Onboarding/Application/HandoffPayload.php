<?php

declare(strict_types=1);

namespace Modules\Onboarding\Application;

use App\Support\SensitiveData;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Minimum application package (4-Day design Section 3, AR-168 acceptance 1). Validation is all-or-nothing.
 * Banking arrives as a token reference only; any raw account/routing/card field or card-number-like value is rejected.
 */
final class HandoffPayload
{
    public const CHANNELS = ['ecommerce', 'card_present', 'moto', 'virtual_terminal', 'payment_link', 'recurring', 'mobile', 'invoicing'];

    /** @return array<string,mixed> */
    public static function rules(bool $requireDealId): array
    {
        return [
            'crm_deal_id' => [$requireDealId ? 'required' : 'nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'merchant_type' => ['nullable', 'in:standard,mobile,test'],
            'business' => ['required', 'array'],
            'business.legal_name' => ['required', 'string', 'min:2', 'max:200'],
            'business.dba' => ['nullable', 'string', 'max:200'],
            'business.ein' => ['required', 'string', 'regex:/^\d{2}-?\d{7}$/'],
            'business.entity_type' => ['required', 'in:llc,corporation,s_corporation,partnership,sole_proprietorship,non_profit,other'],
            'business.years_in_business' => ['nullable', 'integer', 'min:0', 'max:200'],
            'business.website' => ['nullable', 'url:https,http', 'max:255'],
            'business.phone' => ['nullable', 'string', 'max:40'],
            'business.description' => ['nullable', 'string', 'max:2000'],
            'business.timezone' => ['nullable', 'timezone:all'],
            'business.address' => ['required', 'array'],
            'business.address.line1' => ['required', 'string', 'max:200'],
            'business.address.line2' => ['nullable', 'string', 'max:200'],
            'business.address.city' => ['required', 'string', 'max:100'],
            'business.address.region' => ['required', 'string', 'max:10'],
            'business.address.postal_code' => ['required', 'string', 'max:20'],
            'business.address.country' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'contacts' => ['required', 'array', 'min:1', 'max:10'],
            'contacts.*.name' => ['required', 'string', 'max:120'],
            'contacts.*.email' => ['required', 'email:rfc', 'max:200'],
            'contacts.*.phone' => ['nullable', 'string', 'max:40'],
            'contacts.*.role' => ['nullable', 'string', 'max:60'],
            'owners' => ['required', 'array', 'min:1', 'max:10'],
            'owners.*.name' => ['required', 'string', 'max:120'],
            'owners.*.ownership_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'owners.*.title' => ['nullable', 'string', 'max:80'],
            'owners.*.identity_ref' => ['required', 'string', 'max:120'],
            'primary_user' => ['required', 'array'],
            'primary_user.name' => ['required', 'string', 'max:120'],
            'primary_user.email' => ['required', 'email:rfc', 'max:200'],
            'banking' => ['required', 'array'],
            'banking.settlement_token_ref' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\-:.]+$/'],
            'banking.last4' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'commerce' => ['required', 'array'],
            'commerce.mcc' => ['required', 'string', 'regex:/^\d{4}$/'],
            'commerce.mcc_secondary' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'commerce.products' => ['required', 'string', 'max:2000'],
            'commerce.monthly_volume_minor' => ['required', 'integer', 'min:0'],
            'commerce.avg_ticket_minor' => ['required', 'integer', 'min:1'],
            'commerce.max_ticket_minor' => ['required', 'integer', 'min:1', 'gte:commerce.avg_ticket_minor'],
            'commerce.currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'commerce.channels' => ['required', 'array', 'min:1'],
            'commerce.channels.*' => ['required', 'in:'.implode(',', self::CHANNELS)],
            'commerce.cnp_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'commerce.recurring' => ['nullable', 'boolean'],
            'commerce.refund_policy' => ['nullable', 'string', 'max:2000'],
            'commerce.fulfillment' => ['nullable', 'string', 'max:2000'],
            'commerce.chargeback_ratio_bps' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'commerce.prior_processor' => ['nullable', 'string', 'max:120'],
            'documents' => ['nullable', 'array', 'max:50'],
            'documents.*.document_type' => ['required', 'string', 'max:60'],
            'documents.*.reference' => ['required', 'string', 'max:200'],
            'agent' => ['required', 'array'],
            'agent.agent_id' => ['required', 'string', 'max:60'],
            'agent.name' => ['required', 'string', 'max:120'],
            'agent.iso' => ['nullable', 'string', 'max:120'],
            'services' => ['nullable', 'array'],
            'services.*' => ['string', 'max:60'],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed> validated data
     *
     * @throws ValidationException
     */
    public static function validate(array $payload, bool $requireDealId): array
    {
        $extra = [];
        if (($key = SensitiveData::findForbiddenKey($payload)) !== null) {
            $extra[$key] = ['Raw bank or card fields are not accepted. Send a token reference.'];
        }
        if (($path = SensitiveData::findPan($payload)) !== null) {
            $extra[$path] = ['A value looks like a card number and was rejected.'];
        }
        $validator = Validator::make($payload, self::rules($requireDealId));
        $validator->after(function ($v) use ($extra, $payload): void {
            foreach ($extra as $field => $messages) {
                foreach ($messages as $message) {
                    $v->errors()->add($field, $message);
                }
            }
            $total = array_sum(array_map(fn ($o) => (int) ($o['ownership_percent'] ?? 0), (array) ($payload['owners'] ?? [])));
            if ($total > 100) {
                $v->errors()->add('owners', 'Ownership percentages add up to more than 100.');
            }
        });

        return $validator->validate();
    }
}
