<?php

use App\Mcp\Tools\SendMessage;
use App\Models\Agent;
use App\Models\AgentMessage;
use App\Models\User;
use App\Models\VfsFile;
use App\Services\MessageService;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->sender = Agent::factory()->withKey()->create();
    $this->recipient = Agent::factory()->withKey('melf_recipient')->create();

    $this->mcp = function (string $method, array $params = [], ?string $bearer = 'melf_testkey', array $headers = []) {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ], array_merge(['Accept' => 'application/json'], $headers, $bearer === null ? [] : ['Authorization' => 'Bearer '.$bearer]));
    };

    $this->tool = function (string $name, array $arguments = [], ?string $bearer = 'melf_testkey', array $headers = []) {
        return ($this->mcp)('tools/call', ['name' => $name, 'arguments' => $arguments], $bearer, $headers);
    };

    $this->toolJson = function (string $name, array $arguments = [], ?string $bearer = 'melf_testkey', array $headers = []) {
        $result = ($this->tool)($name, $arguments, $bearer, $headers)->assertOk()->json('result');

        return json_decode($result['content'][0]['text'] ?? '', true) ?? [];
    };
});

it('registers the messaging tools on the server', function () {
    $names = collect(($this->mcp)('tools/list', ['per_page' => 50])->assertOk()->json('result.tools'))
        ->pluck('name');

    foreach (['list_message_recipients', 'send_message', 'list_inbox', 'list_sent_messages', 'read_message', 'ack_message'] as $tool) {
        expect($names)->toContain($tool);
    }
});

it('lists active recipients with id and name, excluding the caller and disabled agents', function () {
    Agent::factory()->disabled()->create(['name' => 'disabled-one']);

    $recipients = ($this->toolJson)('list_message_recipients')['recipients'];

    expect($recipients)->toBe([
        ['id' => $this->recipient->id, 'name' => $this->recipient->name],
    ]);
});

it('delivers a message and reports stable fields', function () {
    $result = ($this->toolJson)('send_message', [
        'recipient_id' => $this->recipient->id,
        'subject' => 'hello',
        'body' => 'meet me at the dock',
        'attachments' => [['name' => 'notes/berth.txt', 'content' => 'dock 7']],
    ]);

    expect($result['recipient_id'])->toBe($this->recipient->id)
        ->and($result['recipient_name'])->toBe($this->recipient->name)
        ->and($result['attachments'])->toBe(1)
        ->and($result['id'])->toBeInt();

    expect(AgentMessage::find($result['id'])->body)->toBe('meet me at the dock');
});

it('resolves vfs_path attachments inside the sender tree only', function () {
    VfsFile::writeFor($this->sender, 'docs/plan.txt', 'plan contents');

    $result = ($this->toolJson)('send_message', [
        'recipient_id' => $this->recipient->id,
        'body' => 'see attached',
        'attachments' => [['vfs_path' => 'docs/plan.txt']],
    ]);

    $attachment = AgentMessage::find($result['id'])->attachments->sole();
    expect($attachment->name)->toBe('docs/plan.txt')
        ->and($attachment->content)->toBe('plan contents');

    // A path the sender doesn't own fails instead of leaking.
    $error = ($this->tool)('send_message', [
        'recipient_id' => $this->recipient->id,
        'body' => 'hi',
        'attachments' => [['vfs_path' => 'docs/missing.txt']],
    ])->assertOk()->json('result');

    expect($error['isError'])->toBeTrue();
});

it('rejects self-delivery and unknown recipients', function () {
    $self = ($this->tool)('send_message', [
        'recipient_id' => $this->sender->id, 'body' => 'hi',
    ])->assertOk()->json('result');
    expect($self['isError'])->toBeTrue();

    $unknown = ($this->tool)('send_message', [
        'recipient_id' => 99999, 'body' => 'hi',
    ])->assertOk()->json('result');
    expect($unknown['isError'])->toBeTrue();
});

it('lists inbox messages scoped to the recipient with previews', function () {
    ($this->toolJson)('send_message', [
        'recipient_id' => $this->recipient->id, 'body' => 'first',
    ]);
    ($this->toolJson)('send_message', [
        'recipient_id' => $this->recipient->id, 'body' => 'second',
    ]);

    $inbox = ($this->toolJson)('list_inbox', [], 'melf_recipient');

    expect($inbox['total'])->toBe(2)
        ->and($inbox['unread'])->toBe(2)
        ->and($inbox['messages'][0]['preview'])->toBe('second')
        ->and($inbox['messages'][0])->not->toHaveKey('body');

    // The sender's own inbox stays empty — no leak across agents.
    expect(($this->toolJson)('list_inbox')['total'])->toBe(0);
});

it('filters the inbox to unread', function () {
    $sent = ($this->toolJson)('send_message', ['recipient_id' => $this->recipient->id, 'body' => 'read me']);
    ($this->toolJson)('send_message', ['recipient_id' => $this->recipient->id, 'body' => 'unread me']);
    ($this->toolJson)('read_message', ['id' => $sent['id']], 'melf_recipient');

    $unread = ($this->toolJson)('list_inbox', ['unread' => true], 'melf_recipient');

    expect($unread['total'])->toBe(1)
        ->and($unread['messages'][0]['preview'])->toBe('unread me');
});

it('lists sent messages scoped to the sender', function () {
    ($this->toolJson)('send_message', ['recipient_id' => $this->recipient->id, 'body' => 'outbound']);
    ($this->toolJson)('send_message', ['recipient_id' => $this->sender->id, 'body' => 'bounce'], 'melf_recipient');

    $sent = ($this->toolJson)('list_sent_messages');

    expect($sent['total'])->toBe(1)
        ->and($sent['messages'][0]['to'])->toBe($this->recipient->name);
});

it('paginates inbox and sent past page 1', function () {
    $service = app(MessageService::class);
    for ($i = 1; $i <= 55; $i++) {
        $service->send($this->sender, $this->recipient->id, null, "msg $i");
    }

    $page1 = ($this->toolJson)('list_inbox', ['page' => 1], 'melf_recipient');
    $page2 = ($this->toolJson)('list_inbox', ['page' => 2], 'melf_recipient');

    expect($page1['total'])->toBe(55)
        ->and(count($page1['messages']))->toBe(50)
        ->and($page1['messages'][0]['preview'])->toBe('msg 55')
        ->and($page2['page'])->toBe(2)
        ->and(count($page2['messages']))->toBe(5)
        ->and($page2['messages'][0]['preview'])->toBe('msg 5');

    $sent2 = ($this->toolJson)('list_sent_messages', ['page' => 2]);
    expect($sent2['page'])->toBe(2)
        ->and(count($sent2['messages']))->toBe(5);
});

it('does not consume send quota on a failed send', function () {
    $rateKey = 'mcp-message-send:'.$this->sender->id;
    for ($i = 0; $i < SendMessage::SEND_LIMIT_PER_MINUTE - 1; $i++) {
        RateLimiter::hit($rateKey, 60);
    }

    $error = ($this->tool)('send_message', ['recipient_id' => 999999, 'body' => 'hi'])
        ->assertOk()->json('result');
    expect($error['isError'])->toBeTrue();

    // The failed send must not have burned the last slot.
    $ok = ($this->toolJson)('send_message', ['recipient_id' => $this->recipient->id, 'body' => 'hi']);
    expect($ok['recipient_id'])->toBe($this->recipient->id);
});

it('marks the message read when the recipient reads it, returning attachments', function () {
    $sent = ($this->toolJson)('send_message', [
        'recipient_id' => $this->recipient->id,
        'body' => 'full body',
        'attachments' => [['name' => 'a.txt', 'content' => 'alpha']],
    ]);

    $read = ($this->toolJson)('read_message', ['id' => $sent['id']], 'melf_recipient');

    expect($read['body'])->toBe('full body')
        ->and($read['attachments'][0]['content'])->toBe('alpha')
        ->and($read['read_at'])->not->toBeNull()
        ->and($read['acknowledged_at'])->toBeNull();
});

it('does not mark read when the sender re-reads their own copy', function () {
    $sent = ($this->toolJson)('send_message', ['recipient_id' => $this->recipient->id, 'body' => 'hi']);

    $read = ($this->toolJson)('read_message', ['id' => $sent['id']]);

    expect($read['read_at'])->toBeNull();
});

it('fails closed on messages between other agents', function () {
    $sent = ($this->toolJson)('send_message', ['recipient_id' => $this->recipient->id, 'body' => 'private']);

    $outsider = Agent::factory()->withKey('melf_outsider')->create();
    $error = ($this->tool)('read_message', ['id' => $sent['id']], 'melf_outsider')->assertOk()->json('result');

    expect($error['isError'])->toBeTrue()
        ->and($error['content'][0]['text'])->toBe('Message not found.');
});

it('acknowledges a message as the recipient, idempotently and implying read', function () {
    $sent = ($this->toolJson)('send_message', ['recipient_id' => $this->recipient->id, 'body' => 'ack me']);

    $ack = ($this->toolJson)('ack_message', ['id' => $sent['id']], 'melf_recipient');
    expect($ack['acknowledged_at'])->not->toBeNull()
        ->and($ack['read_at'])->not->toBeNull();

    $again = ($this->toolJson)('ack_message', ['id' => $sent['id']], 'melf_recipient');
    expect($again['acknowledged_at'])->toBe($ack['acknowledged_at']);
});

it('rejects acknowledgement from the sender or an unrelated agent', function () {
    $sent = ($this->toolJson)('send_message', ['recipient_id' => $this->recipient->id, 'body' => 'hi']);

    $senderAck = ($this->tool)('ack_message', ['id' => $sent['id']])->assertOk()->json('result');
    expect($senderAck['isError'])->toBeTrue();

    $outsider = Agent::factory()->withKey('melf_outsider')->create();
    $otherAck = ($this->tool)('ack_message', ['id' => $sent['id']], 'melf_outsider')->assertOk()->json('result');
    expect($otherAck['isError'])->toBeTrue();

    expect(AgentMessage::find($sent['id'])->acknowledged_at)->toBeNull();
});

it('rate limits send attempts', function () {
    $rateKey = 'mcp-message-send:'.$this->sender->id;
    RateLimiter::hit($rateKey, 60);
    for ($i = 0; $i < SendMessage::SEND_LIMIT_PER_MINUTE - 1; $i++) {
        RateLimiter::hit($rateKey, 60);
    }

    $error = ($this->tool)('send_message', ['recipient_id' => $this->recipient->id, 'body' => 'hi'])
        ->assertOk()->json('result');

    expect($error['isError'])->toBeTrue()
        ->and($error['content'][0]['text'])->toContain('rate limit');
});

it('resolves the same identity across dedicated, fleet, and OAuth auth', function () {
    $owner = User::factory()->owner()->create();
    $fleetKey = $owner->rotateMcpKey();
    $oauth = issueOAuthToken($owner, $this->sender, registerClient()->json('client_id'));

    foreach ([
        ['melf_testkey', []],
        [$fleetKey, ['X-Melfina-Agent-Key' => $this->sender->name]],
        [$oauth, []],
    ] as [$bearer, $headers]) {
        $result = ($this->toolJson)('send_message', [
            'recipient_id' => $this->recipient->id,
            'body' => 'auth path check',
        ], $bearer, $headers);

        expect($result['recipient_id'] ?? null)->toBe($this->recipient->id);
    }

    expect(AgentMessage::query()->where('sender_agent_id', $this->sender->id)->count())->toBe(3);
});

it('lets a fleet client name its persona inside the call', function () {
    $owner = User::factory()->owner()->create();
    $fleetKey = $owner->rotateMcpKey();

    $result = ($this->toolJson)('send_message', [
        'name' => $this->sender->name,
        'recipient_id' => $this->recipient->id,
        'body' => 'fleet persona send',
    ], $fleetKey);

    expect($result['recipient_id'])->toBe($this->recipient->id);
});
