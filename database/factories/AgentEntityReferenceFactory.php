<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\AgentEntityReference;
use App\Models\AgentEntityReferenceKind;
use App\Models\KnowledgeEntry;
use App\Support\KnowledgeName;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentEntityReference>
 */
class AgentEntityReferenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $value = fake()->unique()->firstName();

        return [
            'agent_id' => Agent::factory(),
            'entry_id' => KnowledgeEntry::factory()->state(['type' => 'person']),
            'value' => $value,
            'normalized_value' => KnowledgeName::normalize($value),
            'kind' => AgentEntityReferenceKind::AddressTerm,
            'author' => 'owner',
        ];
    }
}
