<?php

declare(strict_types=1);

namespace Modules\Identity\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class AccountLockedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $name) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your AmpliRail account was locked');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.account-locked', with: ['name' => $this->name, 'minutes' => config('amplirail.auth.lockout_minutes')]);
    }
}
