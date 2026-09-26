<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\AgentMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentMessage>
 */
class AgentMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sender_agent_id' => Agent::factory(),
            'recipient_agent_id' => Agent::factory(),
            'sender_name' => fn (array $attributes) => Agent::find($attributes['sender_agent_id'])?->name ?? 'sender',
            'recipient_name' => fn (array $attributes) => Agent::find($attributes['recipient_agent_id'])?->name ?? 'recipient',
            'subject' => fake()->optional()->sentence(4),
            'body' => fake()->paragraph(),
        ];
    }

    public function between(Agent $sender, Agent $recipient): static
    {
        return $this->state(fn (array $attributes) => [
            'sender_agent_id' => $sender->id,
            'sender_name' => $sender->name,
            'recipient_agent_id' => $recipient->id,
            'recipient_name' => $recipient->name,
        ]);
    }

    public function read(): static
    {
        return $this->state(fn (array $attributes) => [
            'read_at' => now(),
        ]);
    }

    public function acknowledged(): static
    {
        return $this->state(fn (array $attributes) => [
            'read_at' => now(),
            'acknowledged_at' => now(),
        ]);
    }
}
