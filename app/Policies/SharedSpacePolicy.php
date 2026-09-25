<?php

namespace App\Policies;

use App\Models\SharedSpace;
use App\Models\User;

class SharedSpacePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_owner;
    }

    public function view(User $user, SharedSpace $space): bool
    {
        return $user->is_owner;
    }

    public function create(User $user): bool
    {
        return $user->is_owner;
    }

    public function update(User $user, SharedSpace $space): bool
    {
        return $user->is_owner;
    }

    public function delete(User $user, SharedSpace $space): bool
    {
        return $user->is_owner;
    }
}
