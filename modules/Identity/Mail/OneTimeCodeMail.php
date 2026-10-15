<?php

declare(strict_types=1);

namespace Modules\Identity\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent synchronously (never queued) so the code is not persisted in the jobs table. */
final class OneTimeCodeMail extends Mailable
{
    public function __construct(public readonly string $code, public readonly string $purpose) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your AmpliRail verification code');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.one-time-code', with: ['code' => $this->code, 'purpose' => $this->purpose, 'minutes' => config('amplirail.auth.otp_ttl_minutes')]);
    }
}
