<?php

declare(strict_types=1);

namespace Modules\Identity\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class NewDeviceMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $name, public readonly string $ip, public readonly string $userAgent, public readonly string $when) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'New sign-in to your AmpliRail account');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.new-device', with: ['name' => $this->name, 'ip' => $this->ip, 'agent' => $this->userAgent, 'when' => $this->when]);
    }
}
