<?php

namespace App\Policies;

use App\Models\Instruction;
use App\Models\User;

class InstructionPolicy
{
    /**
     * Only the owner reads or shapes instructions. Agents have no session
     * role — their read-only access arrives later via MCP (#7).
     */
    public function view(User $user, Instruction $instruction): bool
    {
        return $user->is_owner;
    }

    public function create(User $user): bool
    {
        return $user->is_owner;
    }

    public function update(User $user, Instruction $instruction): bool
    {
        return $user->is_owner;
    }

    public function delete(User $user, Instruction $instruction): bool
    {
        return $user->is_owner;
    }
}
