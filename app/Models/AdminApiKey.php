<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An owner-minted admin API key (`melfa_…`) for the provisioning surface
 * (/api/admin) — what a fleet system such as Yukihime presents to create
 * brains and mint their `melf_` keys without a human in the console. Each
 * key carries a permissions allowlist so a consumer can be
 * least-privileged; the plaintext is shown once and only the hash is
 * stored. It can never call tools or resolve to an agent persona — every
 * other credential surface fails closed on the `melfa_` prefix.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $key_hash
 * @property list<string> $permissions
 * @property Carbon|null $revoked_at
 */
#[Fillable(['user_id', 'name', 'key_hash', 'permissions', 'revoked_at'])]
class AdminApiKey extends Model
{
    public const PREFIX = 'melfa_';

    public const PERMISSION_AGENTS_READ = 'agents:read';

    public const PERMISSION_AGENTS_WRITE = 'agents:write';

    public const PERMISSION_KEYS_MINT = 'keys:mint';

    public const PERMISSION_STATUS_READ = 'status:read';

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'revoked_at' => 'datetime',
        ];
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
     * Whether this key may use an endpoint that requires the given
     * permission. Exact match only — unlike tool scopes there are no
     * `all:*` presets, so every admin credential states its whole
     * allowlist explicitly.
     */
    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissions ?? [], true);
    }

    /**
     * @return list<string>
     */
    public static function permissionChoices(): array
    {
        return [
            self::PERMISSION_AGENTS_READ,
            self::PERMISSION_AGENTS_WRITE,
            self::PERMISSION_KEYS_MINT,
            self::PERMISSION_STATUS_READ,
        ];
    }

    /**
     * Issue a new named key for the owner, returning the plaintext (shown
     * once) — only the hash is stored. Unknown permissions are dropped; a
     * key that ends up with none is refused (fails closed).
     *
     * @param  list<string>  $permissions
     */
    public static function issue(User $owner, string $name, array $permissions): string
    {
        $permissions = array_values(array_intersect($permissions, self::permissionChoices()));

        if ($permissions === []) {
            throw new \InvalidArgumentException('An admin key needs at least one known permission.');
        }

        $plaintext = self::PREFIX.Str::random(48);

        static::query()->create([
            'user_id' => $owner->id,
            'name' => $name,
            'key_hash' => Agent::hashKey($plaintext),
            'permissions' => $permissions,
        ]);

        return $plaintext;
    }

    /**
     * Resolve a live admin key from its plaintext. Fails closed on a
     * wrong prefix, an unknown hash, or a revoked key.
     */
    public static function forKey(string $plaintext): ?self
    {
        if (! str_starts_with($plaintext, self::PREFIX)) {
            return null;
        }

        return static::query()
            ->where('key_hash', Agent::hashKey($plaintext))
            ->whereNull('revoked_at')
            ->first();
    }
}
