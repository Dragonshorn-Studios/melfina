<?php

namespace Database\Factories;

use App\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->userName(),
            'status' => Agent::STATUS_ACTIVE,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Agent::STATUS_DISABLED,
        ]);
    }

    /**
     * Give the agent a stored key hash and expose the plaintext via the
     * `plaintextKey` factory attribute for tests.
     */
    public function withKey(string $plaintext = 'melf_testkey'): static
    {
        return $this->state(fn (array $attributes) => [
            'key_hash' => Agent::hashKey($plaintext),
        ]);
    }
}
