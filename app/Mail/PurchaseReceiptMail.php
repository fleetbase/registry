<?php

namespace App\Mail;

use App\Models\Purchase;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the buyer when the webhook records a purchase: what was bought, the licence and a link
 * to Stripe's receipt.
 */
class PurchaseReceiptMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Purchase $purchase) {}

    public function envelope(): Envelope
    {
        $this->purchase->loadMissing('extension');

        return new Envelope(subject: 'Your receipt for '.$this->purchase->extension->name);
    }

    public function content(): Content
    {
        $this->purchase->loadMissing(['extension', 'buyer', 'licence']);

        return new Content(
            markdown: 'mail.purchase-receipt',
            with: [
                'extension' => $this->purchase->extension->name,
                'organization' => $this->purchase->buyer?->name,
                'amount' => number_format($this->purchase->amount_total / 100, 2).' '.$this->purchase->currency,
                'seats' => $this->purchase->licence?->max_activations,
                'receiptUrl' => $this->purchase->receipt_url,
                'billingUrl' => rtrim((string) (config('registry.public_url') ?: config('app.url')), '/').'/account/billing/'.$this->purchase->buyer?->slug,
                'purchaseId' => $this->purchase->public_id,
            ],
        );
    }
}
