<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Directed agent-to-agent messaging. This service is the authorization
 * boundary for the module: every agent-scoped method takes the acting
 * agent explicitly, and anything the agent is not a party to fails closed
 * as "not found" — no metadata leaks to unrelated agents.
 *
 * Rules (from the messaging spec):
 * - exactly one sender and one recipient, both existing active agents
 * - no self-delivery
 * - disabled agents cannot send; disabled recipients cannot accumulate
 *   mail (fail closed)
 * - only the recipient may set read_at/acknowledged_at; both transitions
 *   are idempotent, and acknowledgement implies read
 * - body and attachment snapshots are immutable after delivery
 * - sending never touches VFS, instructions, Shared spaces, or memory
 */
class MessageService
{
    public const PAGE_SIZE = 50;

    /**
     * Active agents the sender may address — id + display name only.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    public function addressBook(Agent $agent): Collection
    {
        return Agent::query()
            ->where('status', Agent::STATUS_ACTIVE)
            ->where('id', '!=', $agent->id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Agent $a) => ['id' => $a->id, 'name' => $a->name]);
    }

    /**
     * Deliver a message. Attachments are `array{name: string, content: string}`
     * snapshots — callers resolve any VFS paths themselves first, so content
     * stored here never ties back to a live file.
     *
     * @param  array<int, array{name: string, content: string}>  $attachments
     *
     * @throws InvalidArgumentException on validation failure (self/unknown/
     *                                  disabled recipient, disabled sender, limits)
     */
    public function send(Agent $sender, int $recipientId, ?string $subject, string $body, array $attachments = []): AgentMessage
    {
        if (! $sender->isActive()) {
            throw new InvalidArgumentException('Disabled agents cannot send messages.');
        }

        $recipient = Agent::query()->find($recipientId);

        if ($recipient === null || ! $recipient->isActive()) {
            throw new InvalidArgumentException('Unknown or inactive recipient.');
        }

        if ($recipient->id === $sender->id) {
            throw new InvalidArgumentException('Cannot send a message to yourself.');
        }

        $this->assertWithinLimits($subject, $body, $attachments);

        return DB::transaction(function () use ($sender, $recipient, $subject, $body, $attachments) {
            /** @var AgentMessage $message */
            $message = AgentMessage::query()->create([
                'sender_agent_id' => $sender->id,
                'sender_name' => $sender->name,
                'recipient_agent_id' => $recipient->id,
                'recipient_name' => $recipient->name,
                'subject' => $subject !== null && trim($subject) !== '' ? trim($subject) : null,
                'body' => $body,
            ]);

            $message->attachments()->createMany($attachments);

            return $message->load('attachments');
        });
    }

    /**
     * Newest-first page of the agent's received messages.
     *
     * @return LengthAwarePaginator<int, AgentMessage>
     */
    public function inbox(Agent $recipient, bool $unreadOnly = false, int $perPage = self::PAGE_SIZE, int $page = 1): LengthAwarePaginator
    {
        return AgentMessage::query()
            ->where('recipient_agent_id', $recipient->id)
            ->when($unreadOnly, fn ($q) => $q->whereNull('read_at'))
            ->withCount('attachments')
            ->latest('id')
            ->paginate(min(max(1, $perPage), 100), ['*'], 'page', $page);
    }

    /**
     * Newest-first page of messages the agent sent.
     *
     * @return LengthAwarePaginator<int, AgentMessage>
     */
    public function sent(Agent $sender, int $perPage = self::PAGE_SIZE, int $page = 1): LengthAwarePaginator
    {
        return AgentMessage::query()
            ->where('sender_agent_id', $sender->id)
            ->withCount('attachments')
            ->latest('id')
            ->paginate(min(max(1, $perPage), 100), ['*'], 'page', $page);
    }

    /**
     * Fetch one message for the agent — null when it doesn't exist or the
     * agent is not a party (fail closed, indistinguishable). Reading as the
     * recipient marks it read; the sender's copy is untouched by reads.
     */
    public function readFor(Agent $agent, int $messageId): ?AgentMessage
    {
        $message = AgentMessage::query()->with('attachments')->find($messageId);

        if ($message === null || ! $message->involves($agent)) {
            return null;
        }

        if ($message->isRecipient($agent)) {
            $this->markRead($agent, $message);
        }

        return $message;
    }

    /**
     * Mark a received message read. Recipient-only, idempotent, preserves
     * the first-read timestamp.
     */
    public function markRead(Agent $agent, AgentMessage $message): ?AgentMessage
    {
        if (! $message->isRecipient($agent)) {
            return null;
        }

        if ($message->read_at === null) {
            $message->forceFill(['read_at' => now()])->save();
        }

        return $message;
    }

    /**
     * Acknowledge a received message. Recipient-only, idempotent, implies read.
     */
    public function acknowledge(Agent $agent, AgentMessage $message): ?AgentMessage
    {
        if (! $message->isRecipient($agent)) {
            return null;
        }

        $now = now();
        $dirty = [];

        if ($message->read_at === null) {
            $dirty['read_at'] = $now;
        }

        if ($message->acknowledged_at === null) {
            $dirty['acknowledged_at'] = $now;
        }

        if ($dirty !== []) {
            $message->forceFill($dirty)->save();
        }

        return $message;
    }

    /**
     * Unread inbox count — cheap signal for "mail is waiting" hints.
     */
    public function unreadCount(Agent $recipient): int
    {
        return AgentMessage::query()
            ->where('recipient_agent_id', $recipient->id)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * @param  array<int, array{name: string, content: string}>  $attachments
     */
    private function assertWithinLimits(?string $subject, string $body, array $attachments): void
    {
        if ($subject !== null && mb_strlen($subject) > AgentMessage::MAX_SUBJECT_LENGTH) {
            throw new InvalidArgumentException('Subject exceeds '.AgentMessage::MAX_SUBJECT_LENGTH.' characters.');
        }

        if (strlen($body) > AgentMessage::MAX_BODY_BYTES) {
            throw new InvalidArgumentException('Body exceeds '.AgentMessage::MAX_BODY_BYTES.' bytes.');
        }

        if (count($attachments) > AgentMessage::MAX_ATTACHMENTS) {
            throw new InvalidArgumentException('At most '.AgentMessage::MAX_ATTACHMENTS.' attachments per message.');
        }

        $total = strlen($body);
        foreach ($attachments as $attachment) {
            if (! is_array($attachment)
                || ! is_string($attachment['name'] ?? null)
                || ! is_string($attachment['content'] ?? null)) {
                throw new InvalidArgumentException('Each attachment must be a {name, content} pair.');
            }

            if (mb_strlen($attachment['name']) > AgentMessage::MAX_ATTACHMENT_NAME_LENGTH
                || trim($attachment['name']) === '') {
                throw new InvalidArgumentException('Attachment name is empty or too long.');
            }

            $total += strlen($attachment['content']);

            if (strlen($attachment['content']) > AgentMessage::MAX_ATTACHMENT_BYTES) {
                throw new InvalidArgumentException('Attachment exceeds '.AgentMessage::MAX_ATTACHMENT_BYTES.' bytes.');
            }
        }

        if ($total > AgentMessage::MAX_TOTAL_BYTES) {
            throw new InvalidArgumentException('Message exceeds '.AgentMessage::MAX_TOTAL_BYTES.' bytes total.');
        }
    }
}
