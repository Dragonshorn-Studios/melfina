<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\SharedAcl;
use App\Models\SharedSpace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SharedAcl>
 */
class SharedAclFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shared_space_id' => SharedSpace::factory(),
            'agent_id' => Agent::factory(),
            'permission' => SharedAcl::PERMISSION_READ,
        ];
    }
}
