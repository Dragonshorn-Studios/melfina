<?php

namespace Database\Factories;

use App\Models\KnowledgeEntry;
use App\Models\KnowledgeIdentifier;
use App\Models\KnowledgeIdentifierKind;
use App\Support\KnowledgeName;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeIdentifier>
 */
class KnowledgeIdentifierFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $value = fake()->unique()->userName();

        return [
            'entry_id' => KnowledgeEntry::factory(),
            'value' => $value,
            'normalized_value' => KnowledgeName::normalize($value),
            'kind' => KnowledgeIdentifierKind::Alias,
            'author' => 'owner',
        ];
    }
}
