<?php

namespace App\Mail;

use App\Models\Account;
use App\Models\VerificationCode;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerificationCodeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Account $account, public VerificationCode $verificationCode) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.config('app.name').' verification code');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.verification-code',
            with: [
                'name' => $this->account->name,
                'code' => $this->verificationCode->code,
                'minutes' => VerificationCode::LIFETIME_MINUTES,
            ],
        );
    }
}
