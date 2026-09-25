<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Instruction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Instruction>
 */
class InstructionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'content' => fake()->paragraph(),
            'layers' => null,
        ];
    }

    /**
     * @param  array<int, string>  $layers
     */
    public function withLayers(array $layers): static
    {
        return $this->state(fn (array $attributes) => [
            'layers' => $layers,
        ]);
    }
}
