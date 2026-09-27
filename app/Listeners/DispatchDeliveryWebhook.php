<?php

namespace App\Listeners;

use App\Events\AgentMessageDelivered;
use App\Jobs\SendDeliveryWebhook;
use App\Models\User;
use Throwable;

/**
 * Queue the signed host-wake webhook for a delivered message. Best-effort
 * like every listener on this event: when the owner never configured a
 * webhook (or on any failure) it skips quietly — the inbox row is the
 * product, this is just the doorbell.
 */
class DispatchDeliveryWebhook
{
    public function handle(AgentMessageDelivered $event): void
    {
        try {
            $configured = User::query()
                ->where('is_owner', true)
                ->whereNotNull('webhook_url')
                ->exists();

            if ($configured) {
                SendDeliveryWebhook::dispatch($event->message);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
