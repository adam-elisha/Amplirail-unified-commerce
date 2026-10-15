<?php

declare(strict_types=1);

namespace Modules\Identity\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class InvitationMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $name, public readonly string $url, public readonly string $portal) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Set up your AmpliRail '.($this->portal === 'admin' ? 'admin' : 'merchant').' account');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.invitation', with: ['name' => $this->name, 'url' => $this->url, 'hours' => config('amplirail.auth.invitation_ttl_hours')]);
    }
}
