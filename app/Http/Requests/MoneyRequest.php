<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Refund and capture bodies: { transaction_id?, amount: {amount_minor, currency}?, reason? } */
final class MoneyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        $refund = $this->routeIs('api.refunds.store');

        return [
            'transaction_id' => [$refund ? 'required' : 'prohibited', 'string', 'max:40'],
            'amount' => [$refund ? 'required' : 'sometimes', 'array'],
            'amount.amount_minor' => ['required_with:amount', 'integer', 'min:1'],
            'amount.currency' => ['required_with:amount', 'string', 'regex:/^[A-Z]{3}$/'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
