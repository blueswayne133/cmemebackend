<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TokenReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $senderUsername,
        public string $currency
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You've Received {$this->currency} Tokens!",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.token-received',
            with: [
                'senderUsername' => $this->senderUsername,
                'currency' => $this->currency,
            ],
        );
    }
}