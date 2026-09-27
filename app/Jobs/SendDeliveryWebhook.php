<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Models\AgentMessage;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * POST a signed delivery notice to the owner-configured webhook URL —
 * the "wake the host" path for runtimes that poll rather than listen
 * (Grok Bot-style webhook routines). The payload is deliberately minimal:
 * it says *that* mail landed and for whom, never what it says — the host
 * pulls the inbox itself.
 *
 * Retries use the queue backoff; a delivery that exhausts attempts lands
 * in failed_jobs and is logged. Webhook problems never touch the durable
 * inbox row — this job is dispatched after the commit.
 */
class SendDeliveryWebhook implements ShouldQueue
{
    use Queueable;

    /**
     * Attempts before the delivery lands in failed_jobs.
     */
    public int $tries = 5;

    /**
     * Seconds the POST waits for the host before counting as failed.
     */
    public int $timeout = 15;

    public function __construct(
        public AgentMessage $message,
    ) {}

    /**
     * Backoff ladder in seconds between attempts (~25 min total spread).
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 60, 300, 900];
    }

    /**
     * Idempotency key for one delivery — stable across retries and any
     * duplicate dispatch, so a receiver can dedupe on it.
     */
    public static function idempotencyKey(AgentMessage $message): string
    {
        return 'delivery-'.$message->id;
    }

    /**
     * The minimal JSON body sent to the host — enough for a routine to
     * know mail landed and whose inbox to poll. `$recipient` is the live
     * record resolved at send time; when omitted the delivery-time name
     * snapshot is used (rename-safe callers should pass it).
     *
     * @return array{idempotency_key: string, message_id: int, recipient_id: int, recipient_name: string, delivered_at: string}
     */
    public static function payload(AgentMessage $message, ?Agent $recipient = null): array
    {
        return [
            'idempotency_key' => self::idempotencyKey($message),
            'message_id' => $message->id,
            'recipient_id' => $message->recipient_agent_id,
            'recipient_name' => $recipient?->name ?? $message->recipient_name,
            'delivered_at' => $message->created_at->toIso8601String(),
        ];
    }

    public function handle(): void
    {
        $owner = User::query()->where('is_owner', true)->first(['id', 'webhook_url', 'webhook_secret']);

        // Cleared (or never completed) between dispatch and run — skip
        // cleanly rather than fire an unsigned or homeless notice.
        if ($owner === null || ! $owner->hasDeliveryWebhook()) {
            return;
        }

        // Recipient deleted between dispatch and run — no inbox left to
        // wake a host for.
        $recipient = Agent::query()->find($this->message->recipient_agent_id);
        if ($recipient === null) {
            return;
        }

        $body = json_encode(self::payload($this->message, $recipient), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // Timestamp is folded into the signature so receivers can reject
        // captured notices replayed outside their freshness window.
        $timestamp = time();

        $response = Http::timeout(10)
            ->withBody($body, 'application/json')
            ->withHeaders([
                'X-Melfina-Event' => 'message.delivered',
                'X-Melfina-Delivery' => self::idempotencyKey($this->message),
                'X-Melfina-Timestamp' => (string) $timestamp,
                'X-Melfina-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) $owner->webhook_secret),
                'User-Agent' => 'Melfina-Delivery-Webhook/1.0',
            ])
            ->post((string) $owner->webhook_url);

        // 4xx other than 429 won't heal on retry — log and stop; 5xx,
        // 429, and connection errors throw so the queue backs off.
        if ($response->clientError() && $response->status() !== 429) {
            Log::warning('delivery webhook rejected by receiver', [
                'message_id' => $this->message->id,
                'status' => $response->status(),
            ]);

            return;
        }

        if (! $response->successful()) {
            throw new RuntimeException("delivery webhook attempt failed with HTTP {$response->status()}");
        }
    }

    /**
     * All attempts spent — log the final failure; the mail itself is
     * already safe in the recipient's inbox.
     */
    public function failed(Throwable $e): void
    {
        Log::warning('delivery webhook exhausted retries', [
            'message_id' => $this->message->id,
            'error' => $e->getMessage(),
        ]);
    }
}
