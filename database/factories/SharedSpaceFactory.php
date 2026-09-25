<?php

namespace Database\Factories;

use App\Models\SharedSpace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SharedSpace>
 */
class SharedSpaceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
        ];
    }
}
