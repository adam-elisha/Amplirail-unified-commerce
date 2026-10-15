<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\SensitiveData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CreateTransactionRequest extends FormRequest
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
            'amount.amount_minor' => ['required', 'integer', 'min:1', 'max:99999999999'],
            'amount.currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'payment_token' => ['required', 'string', 'min:4', 'max:255'],
            'order_reference' => ['required', 'string', 'max:255'],
            'capture' => ['sometimes', 'boolean'],
            'initiator' => ['sometimes', 'in:customer,merchant'],
            'card' => ['sometimes', 'array'],
            'card.brand' => ['sometimes', 'in:visa,mastercard,amex,discover,jcb,diners,unionpay,other'],
            'card.last4' => ['sometimes', 'regex:/^\d{4}$/'],
            'card.type' => ['sometimes', 'in:credit,debit,prepaid'],
            'simulator' => ['sometimes', 'in:sim_timeout,sim_outage,sim_avs_fail'],
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $v): void {
            if (SensitiveData::findPan($this->all()) !== null) {
                $v->errors()->add('payment_token', 'Card numbers are never accepted. Use a token from AmpliRail hosted fields.');
            }
        }];
    }
}
