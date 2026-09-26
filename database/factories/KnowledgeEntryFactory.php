<?php

namespace Database\Factories;

use App\Models\KnowledgeEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KnowledgeEntry>
 */
class KnowledgeEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => $name,
            'name_lower' => Str::lower($name),
            'type' => fake()->randomElement(KnowledgeEntry::TYPES),
            'aliases' => [],
            'summary' => fake()->sentence(),
            'state' => fake()->paragraph(),
            'related' => [],
            'source' => null,
            'updated_by' => 'test',
            'version' => 1,
        ];
    }
}
