<?php

use App\Jobs\SendDeliveryWebhook;
use App\Models\Agent;
use App\Models\AgentMessage;
use App\Models\User;
use App\Services\MessageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->sender = Agent::factory()->create(['name' => 'sender-one']);
    $this->recipient = Agent::factory()->create(['name' => 'recipient-one']);
    $this->service = app(MessageService::class);
});

it('requires authentication and owner status for webhook settings', function () {
    $this->put('/console/messages/webhook')->assertRedirect();

    $this->actingAs(User::factory()->create(['is_owner' => false]))
        ->put('/console/messages/webhook', ['webhook_url' => 'https://x.test/hook'])
        ->assertForbidden();
});

it('lets the owner set the webhook URL and mints a secret once', function () {
    $response = $this->actingAs($this->owner)
        ->put('/console/messages/webhook', ['webhook_url' => 'https://host.test/wake'])
        ->assertRedirect(route('console.messages.index'));

    $this->owner->refresh();
    expect($this->owner->webhook_url)->toBe('https://host.test/wake')
        ->and($this->owner->webhook_secret)->toStartWith('melfwh_');

    $response->assertSessionHas('plaintext_webhook_secret', $this->owner->webhook_secret);
});

it('keeps the existing secret when the URL is saved again', function () {
    $this->owner->update(['webhook_url' => 'https://old.test']);
    $this->owner->rotateWebhookSecret();
    $secret = $this->owner->webhook_secret;

    $this->actingAs($this->owner)
        ->put('/console/messages/webhook', ['webhook_url' => 'https://new.test/hook']);

    expect($this->owner->fresh()->webhook_secret)->toBe($secret)
        ->and($this->owner->webhook_url)->toBe('https://new.test/hook');
});

it('clears the URL and secret together', function () {
    $this->owner->update(['webhook_url' => 'https://host.test/wake']);
    $this->owner->rotateWebhookSecret();

    $this->actingAs($this->owner)
        ->put('/console/messages/webhook', ['webhook_url' => '']);

    expect($this->owner->fresh()->webhook_url)->toBeNull()
        ->and($this->owner->webhook_secret)->toBeNull();
});

it('rotates the secret and flashes the new plaintext once', function () {
    $this->owner->update(['webhook_url' => 'https://host.test/wake']);
    $old = $this->owner->rotateWebhookSecret();

    $response = $this->actingAs($this->owner)->post('/console/messages/webhook/rotate');

    $new = $this->owner->fresh()->webhook_secret;
    expect($new)->not->toBe($old)->toStartWith('melfwh_');
    $response->assertSessionHas('plaintext_webhook_secret', $new);
});

it('rejects non-http webhook URLs', function () {
    $this->actingAs($this->owner)
        ->put('/console/messages/webhook', ['webhook_url' => 'ftp://host.test/x'])
        ->assertSessionHasErrors('webhook_url');
});

it('queues a webhook job on delivery only when a URL is configured', function () {
    Queue::fake();

    $this->service->send($this->sender, $this->recipient->id, null, 'hi');
    Queue::assertNothingPushed();

    $this->owner->update(['webhook_url' => 'https://host.test/wake']);
    $this->service->send($this->sender, $this->recipient->id, null, 'hi');
    Queue::assertPushed(SendDeliveryWebhook::class, 1);
});

it('posts a signed minimal payload to the configured URL', function () {
    Http::fake(['*' => Http::response(null, 200)]);

    $this->owner->update(['webhook_url' => 'https://host.test/wake']);
    $secret = $this->owner->rotateWebhookSecret();
    $message = AgentMessage::factory()->between($this->sender, $this->recipient)->create();

    (new SendDeliveryWebhook($message))->handle();

    Http::assertSent(function ($request) use ($secret, $message) {
        $payload = $request->data();
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        return $request->url() === 'https://host.test/wake'
            && $request->hasHeader('X-Melfina-Event', 'message.delivered')
            && $request->hasHeader('X-Melfina-Delivery', 'delivery-'.$message->id)
            && $request->hasHeader(
                'X-Melfina-Signature',
                'sha256='.hash_hmac('sha256', $body, $secret)
            )
            && $payload['idempotency_key'] === 'delivery-'.$message->id
            && $payload['message_id'] === $message->id
            && $payload['recipient_id'] === $this->recipient->id
            && $payload['recipient_name'] === 'recipient-one'
            && ! isset($payload['body'], $payload['subject'], $payload['sender_name']);
    });
});

it('produces a payload whose signature a receiver can verify', function () {
    $message = AgentMessage::factory()->between($this->sender, $this->recipient)->create();

    $payload = SendDeliveryWebhook::payload($message);
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $signature = 'sha256='.hash_hmac('sha256', $body, 'melfwh_testsecret');

    // receiver-side verification
    expect(hash_equals('sha256='.hash_hmac('sha256', $body, 'melfwh_testsecret'), $signature))->toBeTrue()
        ->and(hash_equals('sha256='.hash_hmac('sha256', $body, 'wrong'), $signature))->toBeFalse()
        ->and(SendDeliveryWebhook::idempotencyKey($message))->toBe('delivery-'.$message->id);
});

it('skips cleanly when the webhook was cleared between dispatch and run', function () {
    Http::fake();

    $message = AgentMessage::factory()->between($this->sender, $this->recipient)->create();
    (new SendDeliveryWebhook($message))->handle();

    Http::assertNothingSent();
});

it('does not retry a client error other than 429', function () {
    Http::fake(['*' => Http::response('bad request', 400)]);

    $this->owner->update(['webhook_url' => 'https://host.test/wake']);
    $this->owner->rotateWebhookSecret();
    $message = AgentMessage::factory()->between($this->sender, $this->recipient)->create();

    (new SendDeliveryWebhook($message))->handle();

    Http::assertSentCount(1);
});

it('throws on server errors so the queue retries with backoff', function () {
    Http::fake(['*' => Http::response('oops', 503)]);

    $this->owner->update(['webhook_url' => 'https://host.test/wake']);
    $this->owner->rotateWebhookSecret();
    $message = AgentMessage::factory()->between($this->sender, $this->recipient)->create();

    (new SendDeliveryWebhook($message))->handle();
})->throws(RuntimeException::class);

it('webhook failure never rolls back the delivery', function () {
    Http::fake(['*' => Http::response('oops', 503)]);

    $this->owner->update(['webhook_url' => 'https://host.test/wake']);
    $this->owner->rotateWebhookSecret();

    $message = $this->service->send($this->sender, $this->recipient->id, null, 'still delivered');

    expect($message->exists)->toBeTrue()
        ->and($this->service->inbox($this->recipient)->total())->toBe(1);
});

it('renders the webhook panel on the messages screen', function () {
    $this->actingAs($this->owner)
        ->get('/console/messages')
        ->assertOk()
        ->assertSee('DELIVERY WEBHOOK')
        ->assertSee('WEBHOOK URL');

    $this->owner->update(['webhook_url' => 'https://host.test/wake']);
    $this->owner->rotateWebhookSecret();

    $this->actingAs($this->owner)
        ->get('/console/messages')
        ->assertOk()
        ->assertSee('https://host.test/wake')
        ->assertSee('rotate secret')
        ->assertSee('X-Melfina-Signature');
});
