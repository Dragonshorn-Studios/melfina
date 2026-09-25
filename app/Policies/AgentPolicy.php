<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\User;

class AgentPolicy
{
    /**
     * Only the owner mutates agents. There is no agent-facing role for this
     * model — agents authenticate via Bearer/MCP keys, never this policy.
     */
    public function viewAny(User $user): bool
    {
        return $user->is_owner;
    }

    public function view(User $user, Agent $agent): bool
    {
        return $user->is_owner;
    }

    public function create(User $user): bool
    {
        return $user->is_owner;
    }

    public function update(User $user, Agent $agent): bool
    {
        return $user->is_owner;
    }

    public function delete(User $user, Agent $agent): bool
    {
        return $user->is_owner;
    }
}
