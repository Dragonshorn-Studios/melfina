<?php

namespace Database\Factories;

use App\Models\AgentMessage;
use App\Models\AgentMessageAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentMessageAttachment>
 */
class AgentMessageAttachmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_message_id' => AgentMessage::factory(),
            'name' => 'notes/'.fake()->unique()->slug().'.txt',
            'content' => fake()->paragraph(),
        ];
    }
}
