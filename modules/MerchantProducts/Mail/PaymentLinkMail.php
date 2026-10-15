<?php

declare(strict_types=1);

namespace Modules\MerchantProducts\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class PaymentLinkMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $merchant, public readonly string $url, public readonly string $amount, public readonly string $reference) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Payment request from {$this->merchant}");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.payment-link', with: ['merchant' => $this->merchant, 'url' => $this->url, 'amount' => $this->amount, 'reference' => $this->reference]);
    }
}
