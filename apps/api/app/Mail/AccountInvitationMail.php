<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use SensitiveParameter;

final class AccountInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(#[SensitiveParameter] public string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'MediFind account invitation');
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.account-invitation',
            with: [
                'acceptUrl' => url('/invitations/accept?token='.$this->token),
            ],
        );
    }
}
