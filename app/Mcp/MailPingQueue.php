<?php

namespace App\Mcp;

use App\Models\AgentMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Shared mailbox of "check your mail" pings. Pushes happen on
 * AgentMessageDelivered; `subscriptions/listen` streams poll it and yield
 * the pings relevant to their binding. Cache-backed because the ping is a
 * doorbell, not a record — expiry just means a disconnected client finds
 * mail via its next `list_inbox` call.
 */
final class MailPingQueue
{
    private const KEY = 'melfina:mail-pings';

    private const MAX_PINGS = 200;

    private const TTL_SECONDS = 300;

    /**
     * Enqueue a ping for a just-delivered message. The payload deliberately
     * contains only the recipient identity and unread count — never the
     * sender, subject, body, attachments, or VFS paths.
     */
    public static function push(AgentMessage $message, int $unread): void
    {
        $ping = [
            'id' => (string) Str::uuid(),
            'recipient_agent_id' => $message->recipient_agent_id,
            'recipient_name' => $message->recipient_name,
            'unread' => $unread,
        ];

        Cache::lock('melfina:mail-pings:lock', 5)->block(2, function () use ($ping): void {
            $pings = self::all();
            $pings[] = $ping;
            Cache::put(self::KEY, array_slice($pings, -self::MAX_PINGS), self::TTL_SECONDS);
        });
    }

    /**
     * All pending pings, oldest first.
     *
     * @return array<int, array{id: string, recipient_agent_id: int|null, recipient_name: string, unread: int}>
     */
    public static function all(): array
    {
        return Cache::get(self::KEY, []);
    }

    public static function flush(): void
    {
        Cache::forget(self::KEY);
    }
}
