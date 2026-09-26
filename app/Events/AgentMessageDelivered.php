<?php

namespace App\Events;

use App\Models\AgentMessage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by MessageService only after the delivery transaction commits.
 * The durable inbox row is the source of truth; listeners do best-effort
 * notification work that must never fail the send.
 */
class AgentMessageDelivered
{
    use Dispatchable;

    public function __construct(
        public AgentMessage $message,
    ) {}
}
