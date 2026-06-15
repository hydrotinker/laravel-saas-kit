<?php

namespace App\Mail;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued welcome email sent to a new owner after registration.
 *
 * Implementing ShouldQueue makes this a real async job: Laravel wraps it in a
 * SendQueuedMailable job pushed onto the Redis queue and rendered/delivered by
 * the dedicated worker container, not during the web request.
 */
class WelcomeEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Match the worker's retry semantics (queue:work --tries=3 --backoff=10).
     */
    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public readonly User $user,
        public readonly Tenant $tenant,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Welcome to {$this->tenant->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.welcome',
        );
    }
}
