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

    /**
     * A binary row: text column emptied, bytes in the blob, mime set.
     */
    public function binary(string $mime = 'image/png'): static
    {
        return $this->state(fn (): array => [
            'path' => fake()->slug(2).'.bin',
            'content' => '',
            'content_blob' => "\x89PNG\r\n\x1a\n".fake()->sha256(),
            'mime' => $mime,
        ]);
    }
}
