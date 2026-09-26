<?php

namespace App\Listeners;

use App\Events\AgentMessageDelivered;
use App\Mcp\MailPingQueue;
use App\Services\MessageService;
use Throwable;

/**
 * Enqueue a best-effort mail ping for a delivered message. Any failure is
 * reported and swallowed — notification problems must never surface on the
 * send path after the delivery transaction has committed.
 */
class QueueMailPing
{
    public function __construct(
        protected MessageService $messages,
    ) {}

    public function handle(AgentMessageDelivered $event): void
    {
        try {
            $recipient = $event->message->recipient;

            MailPingQueue::push(
                $event->message,
                $recipient !== null ? $this->messages->unreadCount($recipient) : 0,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
