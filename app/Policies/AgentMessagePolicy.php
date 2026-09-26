<?php

namespace App\Policies;

use App\Models\AgentMessage;
use App\Models\User;

class AgentMessagePolicy
{
    /**
     * The owner inspects the fleet's mail; sending and read/ack state stay
     * agent operations through MCP, so no other abilities exist here.
     */
    public function viewAny(User $user): bool
    {
        return $user->is_owner;
    }

    public function view(User $user, AgentMessage $message): bool
    {
        return $user->is_owner;
    }
}
