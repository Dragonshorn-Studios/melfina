<?php

use App\Models\Agent;
use App\Models\AgentMessage;
use App\Models\AgentMessageAttachment;
use App\Models\VfsFile;
use App\Services\MessageService;

beforeEach(function () {
    $this->service = app(MessageService::class);
    $this->sender = Agent::factory()->create();
    $this->recipient = Agent::factory()->create();
});

it('delivers a message with attachment snapshots in one transaction', function () {
    $message = $this->service->send($this->sender, $this->recipient->id, ' hello ', 'body text', [
        ['name' => 'notes/a.txt', 'content' => 'alpha'],
        ['name' => 'notes/b.txt', 'content' => 'beta'],
    ]);

    expect($message->sender_agent_id)->toBe($this->sender->id)
        ->and($message->recipient_name)->toBe($this->recipient->name)
        ->and($message->subject)->toBe('hello')
        ->and($message->attachments)->toHaveCount(2);
});

it('rejects self-delivery', function () {
    $this->service->send($this->sender, $this->sender->id, null, 'hi');
})->throws(InvalidArgumentException::class);

it('rejects disabled senders', function () {
    $this->sender->update(['status' => Agent::STATUS_DISABLED]);

    $this->service->send($this->sender->fresh(), $this->recipient->id, null, 'hi');
})->throws(InvalidArgumentException::class);

it('rejects unknown and disabled recipients (fail closed)', function () {
    $this->service->send($this->sender, 999999, null, 'hi');
})->throws(InvalidArgumentException::class);

it('rejects disabled recipients so they do not accumulate mail', function () {
    $this->recipient->update(['status' => Agent::STATUS_DISABLED]);

    $this->service->send($this->sender, $this->recipient->id, null, 'hi');
})->throws(InvalidArgumentException::class);

it('enforces body, subject, attachment, and total size limits', function () {
    $big = str_repeat('x', AgentMessage::MAX_BODY_BYTES + 1);
    expect(fn () => $this->service->send($this->sender, $this->recipient->id, null, $big))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->service->send($this->sender, $this->recipient->id, str_repeat('s', AgentMessage::MAX_SUBJECT_LENGTH + 1), 'ok'))
        ->toThrow(InvalidArgumentException::class);

    $tooMany = array_fill(0, AgentMessage::MAX_ATTACHMENTS + 1, ['name' => 'a.txt', 'content' => 'x']);
    expect(fn () => $this->service->send($this->sender, $this->recipient->id, null, 'ok', $tooMany))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->service->send($this->sender, $this->recipient->id, null, 'ok', [
        ['name' => 'big.txt', 'content' => str_repeat('y', AgentMessage::MAX_ATTACHMENT_BYTES + 1)],
    ]))->toThrow(InvalidArgumentException::class);
});

it('lists inbox newest-first, scoped to the recipient', function () {
    $other = Agent::factory()->create();
    $this->service->send($this->sender, $this->recipient->id, 'first', 'one');
    $this->service->send($this->sender, $this->recipient->id, 'second', 'two');
    $this->service->send($this->sender, $other->id, 'elsewhere', 'three');

    $inbox = $this->service->inbox($this->recipient);

    expect($inbox->items())->toHaveCount(2)
        ->and($inbox->items()[0]->subject)->toBe('second')
        ->and($this->service->inbox($other)->total())->toBe(1);
});

it('lists sent mail scoped to the sender', function () {
    $this->service->send($this->sender, $this->recipient->id, null, 'one');
    $this->service->send($this->recipient, $this->sender->id, null, 'two');

    expect($this->service->sent($this->sender)->total())->toBe(1)
        ->and($this->service->sent($this->sender)->items()[0]->body)->toBe('one');
});

it('supports an unread-only inbox filter', function () {
    $m1 = $this->service->send($this->sender, $this->recipient->id, null, 'one');
    $this->service->send($this->sender, $this->recipient->id, null, 'two');
    $this->service->readFor($this->recipient, $m1->id);

    $unread = $this->service->inbox($this->recipient, unreadOnly: true);

    expect($unread->total())->toBe(1);
});

it('marks a message read when the recipient reads it, idempotently', function () {
    $message = $this->service->send($this->sender, $this->recipient->id, null, 'hi');

    $read = $this->service->readFor($this->recipient, $message->id);
    expect($read->isRead())->toBeTrue();
    $firstReadAt = $read->read_at->toIso8601String();

    // Second read keeps the original timestamp.
    $again = $this->service->readFor($this->recipient, $message->id);
    expect($again->read_at->toIso8601String())->toBe($firstReadAt);
});

it('does not mark a message read when the sender reads their copy', function () {
    $message = $this->service->send($this->sender, $this->recipient->id, null, 'hi');

    $read = $this->service->readFor($this->sender, $message->id);

    expect($read->isRead())->toBeFalse()
        ->and($message->fresh()->read_at)->toBeNull();
});

it('fails closed for unrelated agents — no message, no metadata', function () {
    $outsider = Agent::factory()->create();
    $message = $this->service->send($this->sender, $this->recipient->id, 'secret subject', 'hi');

    expect($this->service->readFor($outsider, $message->id))->toBeNull()
        ->and($this->service->markRead($outsider, $message))->toBeNull()
        ->and($this->service->acknowledge($outsider, $message))->toBeNull();
});

it('lets only the recipient mutate read/ack state', function () {
    $message = $this->service->send($this->sender, $this->recipient->id, null, 'hi');

    expect($this->service->markRead($this->sender, $message))->toBeNull()
        ->and($this->service->acknowledge($this->sender, $message))->toBeNull()
        ->and($message->fresh()->read_at)->toBeNull()
        ->and($message->fresh()->acknowledged_at)->toBeNull();
});

it('acknowledgement implies read and is idempotent', function () {
    $message = $this->service->send($this->sender, $this->recipient->id, null, 'hi');

    $acked = $this->service->acknowledge($this->recipient, $message);
    expect($acked->isAcknowledged())->toBeTrue()
        ->and($acked->isRead())->toBeTrue();

    $ackAt = $acked->acknowledged_at->toIso8601String();
    $again = $this->service->acknowledge($this->recipient, $message->fresh());
    expect($again->acknowledged_at->toIso8601String())->toBe($ackAt);
});

it('keeps attachment content as an immutable snapshot after VFS changes', function () {
    VfsFile::writeFor($this->sender, 'notes/report.txt', 'v1 content');

    $source = VfsFile::readFor($this->sender, 'notes/report.txt');
    $message = $this->service->send($this->sender, $this->recipient->id, null, 'see attached', [
        ['name' => 'notes/report.txt', 'content' => $source],
    ]);

    VfsFile::writeFor($this->sender, 'notes/report.txt', 'v2 rewritten');

    $attachment = $message->attachments()->first();
    expect($attachment->content)->toBe('v1 content');
});

it('does not touch the sender or recipient VFS on send', function () {
    $this->service->send($this->sender, $this->recipient->id, null, 'hi', [
        ['name' => 'shared/thing.txt', 'content' => 'payload'],
    ]);

    expect(VfsFile::listFor($this->sender)->all())->toBe([])
        ->and(VfsFile::listFor($this->recipient)->all())->toBe([]);
});

it('preserves history with name snapshots when an agent is deleted', function () {
    $message = $this->service->send($this->sender, $this->recipient->id, 'kept', 'history');
    $senderName = $this->sender->name;
    $recipientName = $this->recipient->name;

    $this->recipient->delete();

    $kept = $message->fresh();
    expect($kept->recipient_agent_id)->toBeNull()
        ->and($kept->sender_name)->toBe($senderName)
        ->and($kept->recipient_name)->toBe($recipientName);
});

it('rolls back the whole delivery when an attachment insert fails mid-transaction', function () {
    AgentMessageAttachment::creating(fn () => throw new RuntimeException('insert failed'));

    try {
        $this->service->send($this->sender, $this->recipient->id, null, 'hi', [
            ['name' => 'ok.txt', 'content' => 'fine'],
        ]);
    } catch (RuntimeException) {
        // expected
    }

    expect(AgentMessage::query()->count())->toBe(0)
        ->and(AgentMessageAttachment::query()->count())->toBe(0);
});

it('rejects malformed attachments without leaking query errors', function () {
    foreach ([
        [['name' => 'no-content.txt']],
        [['name' => 'non-string.txt', 'content' => 42]],
        ['not-an-array'],
    ] as $attachments) {
        try {
            $this->service->send($this->sender, $this->recipient->id, null, 'hi', $attachments);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            // expected
        }
    }

    expect(AgentMessage::query()->count())->toBe(0);
});

it('counts unread messages for hint surfaces', function () {
    $this->service->send($this->sender, $this->recipient->id, null, 'one');
    $m2 = $this->service->send($this->sender, $this->recipient->id, null, 'two');

    expect($this->service->unreadCount($this->recipient))->toBe(2);

    $this->service->readFor($this->recipient, $m2->id);
    expect($this->service->unreadCount($this->recipient))->toBe(1);
});
