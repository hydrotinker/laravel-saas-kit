<?php

namespace Tests\Feature;

use App\Mail\WelcomeEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WelcomeEmailQueuedTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_queues_welcome_email_to_the_new_owner(): void
    {
        Mail::fake();

        $this->postJson('/api/register', [
            'organization_name' => 'Acme Inc',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password123',
        ])->assertCreated();

        // assertQueued (not assertSent) proves the mailable is dispatched to the
        // queue for the worker to process, i.e. it really runs asynchronously.
        Mail::assertQueued(WelcomeEmail::class, function (WelcomeEmail $mail) {
            return $mail->hasTo('ada@example.com')
                && $mail->user->email === 'ada@example.com'
                && $mail->tenant->name === 'Acme Inc';
        });
    }

    public function test_failed_registration_does_not_queue_any_email(): void
    {
        Mail::fake();

        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/register', [
            'organization_name' => 'Acme Inc',
            'name' => 'Ada',
            'email' => 'taken@example.com',
            'password' => 'password123',
        ])->assertUnprocessable();

        Mail::assertNothingQueued();
    }
}
