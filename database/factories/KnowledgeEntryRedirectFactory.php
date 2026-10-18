<?php

namespace Database\Factories;

use App\Models\KnowledgeEntry;
use App\Models\KnowledgeEntryRedirect;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeEntryRedirect>
 */
class KnowledgeEntryRedirectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_entry_id' => KnowledgeEntry::factory(),
            'to_entry_id' => KnowledgeEntry::factory(),
            'author' => 'owner',
        ];
    }
}
