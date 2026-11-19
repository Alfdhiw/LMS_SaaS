<?php

namespace App\Modules\Identity\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class SelfRecoveryMail extends Mailable
{
    public function __construct(public readonly string $resetUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Pemulihan password akun LMS');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.self-recovery');
    }
}
