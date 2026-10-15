<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use AmpliRail\Money\Money;
use App\Support\ApiException;
use App\Support\SensitiveData;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\MerchantProducts\Application\PaymentLinkService;
use Modules\Onboarding\Contracts\MerchantDirectory;

/**
 * pay.amplirail.com/l/{token}. Card entry happens ONLY in processor-hosted fields (NMI Collect.js in live); the page
 * posts back a token. In sandbox the simulator tokens are offered instead. A card number is rejected if ever posted.
 */
final class PayController
{
    public function __construct(private readonly PaymentLinkService $links, private readonly MerchantDirectory $merchants) {}

    public function show(string $token): View
    {
        $link = $this->links->open($token) ?? abort(404);
        $profile = $this->merchants->profile($link->merchant_id);

        return view('pay.show', [
            'link' => $link,
            'token' => $token,
            'merchant' => $profile->displayName ?? 'Merchant',
            'amount' => Money::of($link->amount_minor, $link->currency)->format(),
            'collectJs' => $link->environment === 'live' ? (string) config('connectors.nmi_bridge.collect_js_url') : '',
            'tokenizationKey' => (string) config('connectors.nmi_bridge.public_tokenization_key'),
        ]);
    }

    public function pay(Request $request, string $token): View
    {
        $link = $this->links->open($token) ?? abort(404);
        $d = $request->validate([
            'payment_token' => ['required', 'string', 'max:255'],
            'card_brand' => ['nullable', 'in:visa,mastercard,amex,discover,other'],
            'card_last4' => ['nullable', 'regex:/^\d{4}$/'],
            'scenario' => ['nullable', 'in:sim_timeout,sim_outage,sim_avs_fail'],
        ]);
        if (SensitiveData::findPan($request->all()) !== null) {
            throw ApiException::invalid('card_number_rejected', 'Card numbers are never accepted here.');
        }
        $card = isset($d['card_last4']) ? ['brand' => $d['card_brand'] ?? 'other', 'last4' => $d['card_last4']] : null;
        $metadata = $link->environment === 'sandbox' && isset($d['scenario']) ? ['sim' => (string) $d['scenario']] : [];
        $outcome = $this->links->pay($link, (string) $d['payment_token'], $card, $metadata);

        return view('pay.result', [
            'status' => $outcome->status(),
            'merchant' => $this->merchants->profile($link->merchant_id)->displayName ?? 'Merchant',
            'amount' => Money::of($link->amount_minor, $link->currency)->format(),
            'reference' => $link->reference,
            'transactionId' => $outcome->id(),
        ]);
    }
}
