<?php

namespace App\Mcp\Methods;

use App\Mcp\MailPingQueue;
use App\Models\Agent;
use Generator;
use Illuminate\Http\Request;
use Laravel\Mcp\Enums\MetaKey;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * `subscriptions/listen` — the negotiated opt-in notification stream. After
 * the standard acknowledgement it keeps the SSE stream open and yields a
 * `notifications/melfina/mail` notification whenever a mail ping lands in
 * the shared MailPingQueue:
 *
 * - dedicated connections (agent key / agent-bound OAuth) receive only
 *   pings addressed to the bound agent;
 * - fleet-key connections receive every ping, with the recipient persona
 *   fields set so an orchestrator can route it to the right imp.
 *
 * The stream closes after LISTEN_SECONDS; clients re-listen. A client that
 * never opens a subscription simply polls `list_inbox` — the durable inbox
 * stays the source of truth.
 */
class MailListen implements Method
{
    /**
     * How long a listen stream stays open before closing cleanly.
     */
    public const LISTEN_SECONDS = 110;

    /**
     * Mailbox poll interval.
     */
    public const POLL_MICROSECONDS = 500_000;

    /**
     * Emit a heartbeat frame every N polls (~10s) — flushing output is the
     * only thing that refreshes connection_aborted(), so this is what lets
     * a wedged client release the worker before the deadline.
     */
    public const HEARTBEAT_POLLS = 20;

    /**
     * Notification method name for mail pings — a namespaced Melfina
     * extension, deliberately not `notifications/message` (logging).
     */
    public const NOTIFICATION = 'notifications/melfina/mail';

    public function __construct(
        protected Request $http,
    ) {}

    /**
     * @return Generator<int, JsonRpcResponse>
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator
    {
        yield JsonRpcResponse::notification('notifications/subscriptions/acknowledged', [
            '_meta' => [MetaKey::SUBSCRIPTION_ID->value => $request->id],
            'notifications' => (object) [],
        ]);

        yield JsonRpcResponse::result($request->id, [
            '_meta' => [MetaKey::SUBSCRIPTION_ID->value => $request->id],
        ]);

        // Long-lived stream — the generator runs for the whole SSE lifetime.
        set_time_limit(0);

        $fleet = $this->isFleetStream();
        $agent = $this->http->attributes->get('agent');
        $seen = [];
        $deadline = time() + (int) config('melfina.mail_listen_seconds', self::LISTEN_SECONDS);
        $polls = 0;

        while (time() < $deadline && connection_aborted() === 0) {
            foreach (MailPingQueue::all() as $ping) {
                if (isset($seen[$ping['id']])) {
                    continue;
                }

                if (! $fleet && (! $agent instanceof Agent || $ping['recipient_agent_id'] !== $agent->id)) {
                    continue;
                }

                $seen[$ping['id']] = true;

                yield JsonRpcResponse::notification(self::NOTIFICATION, $this->params($ping, $fleet));
            }

            if (++$polls % self::HEARTBEAT_POLLS === 0) {
                echo ': heartbeat'."\n\n";

                if (ob_get_level() !== 0) {
                    ob_flush();
                }

                flush();
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }

    /**
     * Fleet keys open the endpoint without binding to one persona — those
     * streams get every ping. Set by ResolveAgent for `melfg_…` auth.
     */
    private function isFleetStream(): bool
    {
        return $this->http->attributes->get('fleet') === true;
    }

    /**
     * @param  array{id: string, recipient_agent_id: int|null, recipient_name: string, unread: int}  $ping
     * @return array<string, mixed>
     */
    private function params(array $ping, bool $fleet): array
    {
        return [
            'id' => $ping['id'],
            'recipient_agent_id' => $ping['recipient_agent_id'],
            'recipient_name' => $ping['recipient_name'],
            'unread' => $ping['unread'],
            'tool' => 'list_inbox',
            'text' => $fleet
                ? "Mail is waiting for {$ping['recipient_name']}. Call list_inbox with name=\"{$ping['recipient_name']}\"."
                : 'New agent message available. Call list_inbox.',
        ];
    }
}
