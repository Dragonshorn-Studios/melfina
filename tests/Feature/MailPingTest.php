<?php

use App\Events\AgentMessageDelivered;
use App\Mcp\MailPingQueue;
use App\Models\Agent;
use App\Models\AgentMessage;
use App\Models\McpFleetKey;
use App\Models\User;
use App\Services\MessageService;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    MailPingQueue::flush();

    $this->sender = Agent::factory()->withKey()->create(['name' => 'sender']);
    $this->recipient = Agent::factory()->withKey('melf_rcpt')->create(['name' => 'recipient']);
    $this->other = Agent::factory()->withKey('melf_other')->create(['name' => 'other']);
    $this->owner = User::factory()->owner()->create();
});

function pushPing(AgentMessage $message): void
{
    MailPingQueue::push($message, 7);
}

function listen(string $bearer, array $headers = []): string
{
    config()->set('melfina.mail_listen_seconds', 1);

    $body = test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 99,
        'method' => 'subscriptions/listen',
    ], array_merge(['Accept' => 'application/json', 'Authorization' => 'Bearer '.$bearer], $headers))
        ->assertOk()
        ->streamedContent();

    return str_replace(['\\/', '\\"'], ['/', '"'], $body);
}

it('enqueues a mail ping when a message is delivered, with recipient fields only', function () {
    $message = app(MessageService::class)->send($this->sender, $this->recipient->id, 's3cret-subject', 'secret body');

    $pings = MailPingQueue::all();

    expect($pings)->toHaveCount(1)
        ->and($pings[0]['recipient_agent_id'])->toBe($this->recipient->id)
        ->and($pings[0]['recipient_name'])->toBe('recipient')
        ->and($pings[0]['unread'])->toBe(1)
        ->and($pings[0])->not->toHaveKeys(['sender', 'subject', 'body']);
});

it('still delivers when notification dispatch fails', function () {
    Event::listen(AgentMessageDelivered::class, function () {
        throw new RuntimeException('mail queue on fire');
    });

    $message = app(MessageService::class)->send($this->sender, $this->recipient->id, null, 'body ok');

    expect($message->exists)->toBeTrue()
        ->and(AgentMessage::find($message->id)->body)->toBe('body ok');
});

it('streams the ping to a dedicated stream bound to the recipient', function () {
    pushPing(AgentMessage::factory()->between($this->sender, $this->recipient)->create());

    $body = listen('melf_rcpt');

    expect($body)->toContain('notifications/subscriptions/acknowledged')
        ->toContain('notifications/melfina/mail')
        ->toContain('New agent message available. Call list_inbox.')
        ->toContain('"recipient_agent_id":'.$this->recipient->id);
});

it('never pings a dedicated stream for another agent\'s mail', function () {
    pushPing(AgentMessage::factory()->between($this->sender, $this->recipient)->create());

    $body = listen('melf_other');

    expect($body)->toContain('notifications/subscriptions/acknowledged')
        ->not->toContain('notifications/melfina/mail');
});

it('pings fleet streams with persona fields, persona or not', function () {
    $fleet = McpFleetKey::issue($this->owner, 'grokbot');

    pushPing(AgentMessage::factory()->between($this->sender, $this->recipient)->create());

    // Persona-less fleet listen — previously unauthorized.
    $body = listen($fleet);

    expect($body)->toContain('notifications/melfina/mail')
        ->toContain('"recipient_agent_id":'.$this->recipient->id)
        ->toContain('"recipient_name":"recipient"')
        ->toContain('Mail is waiting for recipient. Call list_inbox with name="recipient".')
        ->toContain('"tool":"list_inbox"');

    // A fleet request carrying a persona still hears everyone's mail.
    $body = listen($fleet, ['X-Melfina-Agent-Key' => 'sender']);
    expect($body)->toContain('notifications/melfina/mail');
});

it('does not leak sender, subject, or body in the ping payload', function () {
    pushPing(AgentMessage::factory()->between($this->sender, $this->recipient)->create([
        'subject' => 'TOPSECRET', 'body' => 'hidden body',
    ]));

    $body = listen('melf_rcpt');

    expect($body)->not->toContain('TOPSECRET')
        ->not->toContain('hidden body')
        ->not->toContain('sender');
});

it('requires authentication for listen', function () {
    test()->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'subscriptions/listen'])
        ->assertUnauthorized();
});
