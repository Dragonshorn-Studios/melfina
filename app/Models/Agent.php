<?php

namespace App\Models;

use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $name
 * @property string $status
 * @property string|null $description
 * @property string|null $persona
 * @property array<int, array{at: string, text: string}>|null $memories
 * @property string|null $key_hash
 * @property Carbon|null $key_issued_at
 */
#[Fillable(['name', 'status', 'description', 'persona', 'memories', 'key_hash', 'key_issued_at'])]
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected function casts(): array
    {
        return [
            'key_issued_at' => 'datetime',
            'memories' => 'array',
        ];
    }

    public function instruction(): HasOne
    {
        return $this->hasOne(Instruction::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(VfsFile::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(AgentApiKey::class);
    }

    /**
     * Self-enrollment reports bound to this agent — each accepted one
     * mints short-lived scoped keys on demand.
     *
     * @return HasMany<AgentEnrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(AgentEnrollment::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Append a memory entry. Memories are an append-only log the agent
     * curates itself; the owner can read, append, and prune entries from
     * the console.
     */
    public function remember(string $text): void
    {
        $memories = $this->memories ?? [];
        $memories[] = ['at' => now()->toIso8601String(), 'text' => $text];
        $this->memories = $memories;
        $this->save();
    }

    /**
     * Generate a new plaintext key, store only its hash, and return the plaintext.
     * The plaintext is shown once (create/rotate) and never persisted.
     */
    public function rotateKey(): string
    {
        $plaintext = 'melf_'.Str::random(48);

        $this->key_hash = self::hashKey($plaintext);
        $this->key_issued_at = now();
        $this->save();

        return $plaintext;
    }

    public static function hashKey(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * Resolve an agent from a plaintext Bearer/MCP key. Fails closed.
     */
    public static function forKey(string $plaintext): ?self
    {
        if (! str_starts_with($plaintext, 'melf_')) {
            return null;
        }

        return static::query()
            ->where('key_hash', self::hashKey($plaintext))
            ->where('status', self::STATUS_ACTIVE)
            ->first();
    }

    /**
     * Resolve an agent from a persona identifier sent alongside the fleet
     * key: its `melf_…` key, or simply its name — a non-secret id the agent
     * can know from its own instructions. Names match first, so an agent
     * named `melf_helper` is still claimable; fails closed on no match or
     * an ambiguous (non-unique) name.
     */
    public static function forPersona(string $id): ?self
    {
        $byName = static::query()
            ->where('name', $id)
            ->where('status', self::STATUS_ACTIVE)
            ->limit(2)
            ->get();

        if ($byName->count() === 1) {
            return $byName->first();
        }

        if ($byName->isEmpty() && str_starts_with($id, 'melf_')) {
            return self::forKey($id);
        }

        return null;
    }
}
