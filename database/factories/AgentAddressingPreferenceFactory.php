<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\AgentAddressingPreference;
use App\Models\KnowledgeEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentAddressingPreference>
 */
class AgentAddressingPreferenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'person_entry_id' => KnowledgeEntry::factory()->state(['type' => 'person']),
            'context' => 'default',
            'form' => fake()->firstName(),
            'author' => 'owner',
        ];
    }
}
