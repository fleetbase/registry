<?php

namespace App\Mail;

use App\Models\Purchase;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells Fleetbase staff (`registry.commerce.notify`) that a buyer disputed a purchase.
 */
class DisputeOpenedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Purchase $purchase) {}

    public function envelope(): Envelope
    {
        $this->purchase->loadMissing('extension');

        return new Envelope(subject: 'Dispute opened: '.$this->purchase->extension->name.' ('.$this->purchase->public_id.')');
    }

    public function content(): Content
    {
        $this->purchase->loadMissing(['extension.organization', 'buyer']);
        $dispute = (array) ($this->purchase->meta['dispute'] ?? []);

        return new Content(
            markdown: 'mail.dispute-opened',
            with: [
                'extension' => $this->purchase->extension->name,
                'publisher' => $this->purchase->extension->organization?->name,
                'buyer' => $this->purchase->buyer?->name,
                'amount' => number_format(((int) ($dispute['amount'] ?? $this->purchase->amount_total)) / 100, 2).' '.$this->purchase->currency,
                'reason' => $dispute['reason'] ?? null,
                'disputeId' => $dispute['id'] ?? null,
                'purchaseId' => $this->purchase->public_id,
            ],
        );
    }
}
