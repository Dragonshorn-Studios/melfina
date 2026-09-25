<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\VfsFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VfsFile>
 */
class VfsFileFactory extends Factory
{
    protected $model = VfsFile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'path' => 'notes/'.fake()->unique()->slug().'.txt',
            'content' => fake()->paragraph(),
        ];
    }
}
