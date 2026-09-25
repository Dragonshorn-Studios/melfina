<?php

namespace Database\Factories;

use App\Models\SharedFile;
use App\Models\SharedSpace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SharedFile>
 */
class SharedFileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shared_space_id' => SharedSpace::factory(),
            'path' => fake()->slug(2).'.txt',
            'content' => fake()->paragraph(),
        ];
    }
}
