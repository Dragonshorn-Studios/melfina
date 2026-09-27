<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One named enrollment key — every `melfe_…` credential is a row so a
 * leaked or retired key can be revoked alone. It only lets a runtime
 * report in and later pick up a minted key; it never authenticates a
 * tool call itself.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $key_hash
 * @property Carbon|null $revoked_at
 */
#[Fillable(['user_id', 'name', 'key_hash', 'revoked_at'])]
class AgentEnrollKey extends Model
{
    public const PREFIX = 'melfe_';

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isLive(): bool
    {
        return $this->revoked_at === null;
    }

    public function revoke(): void
    {
        $this->revoked_at = now();
        $this->save();
    }

    /**
     * Whether the plaintext is a live enrollment key. Fails closed on a
     * wrong prefix or a revoked row.
     */
    public static function isLiveKey(string $plaintext): bool
    {
        if (! str_starts_with($plaintext, self::PREFIX)) {
            return false;
        }

        return static::query()
            ->where('key_hash', Agent::hashKey($plaintext))
            ->whereNull('revoked_at')
            ->exists();
    }

    /**
     * Issue a new named key for the owner, returning the plaintext (shown
     * once) — only the hash is stored.
     */
    public static function issue(User $owner, string $name): string
    {
        $plaintext = self::PREFIX.Str::random(48);

        static::query()->create([
            'user_id' => $owner->id,
            'name' => $name,
            'key_hash' => Agent::hashKey($plaintext),
        ]);

        return $plaintext;
    }
}
