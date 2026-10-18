<?php

namespace Database\Factories;

use App\Models\KnowledgeEntry;
use App\Models\KnowledgeLink;
use App\Models\KnowledgeLinkStatus;
use App\Models\KnowledgePredicate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeLink>
 */
class KnowledgeLinkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_entry_id' => KnowledgeEntry::factory(),
            'to_entry_id' => KnowledgeEntry::factory(),
            'predicate' => KnowledgePredicate::RelatedTo,
            'status' => KnowledgeLinkStatus::Active,
            'confidence' => 0,
            'observation_count' => 0,
            'disputed' => false,
            'permanent' => false,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
