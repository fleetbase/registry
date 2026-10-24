<?php

namespace App\Mail;

use App\Models\Account;
use App\Models\VerificationCode;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetCodeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Account $account, public VerificationCode $verificationCode) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.config('app.name').' password reset code');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.password-reset-code',
            with: [
                'name' => $this->account->name,
                'code' => $this->verificationCode->code,
                'minutes' => VerificationCode::LIFETIME_MINUTES,
                'url' => url('/forgot-password?email='.rawurlencode($this->account->email).'&sent=1'),
            ],
        );
    }
}
