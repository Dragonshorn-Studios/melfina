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

    /**
     * A binary row: text column emptied, bytes in the blob, mime set.
     */
    public function binary(string $mime = 'image/png'): static
    {
        return $this->state(fn (): array => [
            'path' => 'assets/'.fake()->unique()->slug().'.bin',
            'content' => '',
            'content_blob' => "\x89PNG\r\n\x1a\n".fake()->sha256(),
            'mime' => $mime,
        ]);
    }
}
