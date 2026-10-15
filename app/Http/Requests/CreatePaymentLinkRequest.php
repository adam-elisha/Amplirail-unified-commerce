<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreatePaymentLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'array'],
            'amount.amount_minor' => ['required', 'integer', 'min:1'],
            'amount.currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'reference' => ['required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'expires_in_hours' => ['sometimes', 'integer', 'min:1', 'max:2160'],
            'send_to' => ['sometimes', 'array'],
            'send_to.email' => ['sometimes', 'email:rfc'],
            'send_to.phone' => ['sometimes', 'string', 'max:30'],
        ];
    }
}
