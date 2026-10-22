<?php

namespace App\Mail;

use App\Models\Release;
use App\Models\Review;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the account that uploaded a release what happened to it: published, rejected (with
 * the reason and items) or failed its checks.
 */
class ReleaseDecisionMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public const PUBLISHED = 'published';

    public const REJECTED = 'rejected';

    public const FAILED_CHECKS = 'failed_checks';

    public function __construct(public Release $release, public string $decision) {}

    public function envelope(): Envelope
    {
        $this->release->loadMissing('extension');
        $subject = match ($this->decision) {
            self::PUBLISHED => 'is published',
            self::REJECTED => 'was not approved',
            default => 'failed its checks',
        };

        return new Envelope(subject: $this->release->extension->name.' '.$this->release->version.' '.$subject);
    }

    public function content(): Content
    {
        $this->release->loadMissing(['extension', 'createdBy', 'checks']);
        $review = $this->release->reviews()->reorder()->latest('id')->first();

        return new Content(
            markdown: 'mail.release-decision',
            with: [
                'name' => $this->release->createdBy?->name,
                'extension' => $this->release->extension->name,
                'version' => $this->release->version,
                'decision' => $this->decision,
                'reason' => $review?->action === Review::ACTION_REJECT ? $review->reason : null,
                'items' => $review?->action === Review::ACTION_REJECT ? ($review->items ?? []) : [],
                'failedChecks' => $this->release->checks->where('passed', false)->map(fn ($check) => $check->check.': '.($check->output['summary'] ?? ''))->values()->all(),
                'releaseId' => $this->release->public_id,
            ],
        );
    }
}
