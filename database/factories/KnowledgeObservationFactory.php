<?php

namespace Database\Factories;

use App\Models\KnowledgeAuthorKind;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeObservation;
use App\Models\KnowledgeObservationKind;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeObservation>
 */
class KnowledgeObservationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => KnowledgeObservationKind::LinkAssert,
            'entry_id' => KnowledgeEntry::factory(),
            'link_id' => null,
            'author' => 'grokbot',
            'author_kind' => KnowledgeAuthorKind::Agent,
            'payload' => [],
            'source' => null,
            'occurred_at' => null,
        ];
    }
}
