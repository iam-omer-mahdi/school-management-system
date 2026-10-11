<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public int $minutes = 10,
        public ?string $mailSubject = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->mailSubject ?? __('auth.otp_email_subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.auth.reset-code-text',
            with: [
                'code' => $this->code,
                'minutes' => $this->minutes,
            ],
        );
    }
}
