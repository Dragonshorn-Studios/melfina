<?php

namespace App\Models;

use App\Mcp\ToolCatalog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A scoped per-agent API key (`melfs_…`) for the HTTP tool fallback —
 * POST /api/tools/{tool} — issued for runtimes that cannot install the
 * Melfina MCP server. Each key carries an allowlist of tool scopes so a
 * weaker/cloud agent gets least-privilege access; the plaintext is shown
 * once and only the hash is stored.
 *
 * @property int $id
 * @property int $agent_id
 * @property int|null $enrollment_id
 * @property string $name
 * @property string $key_hash
 * @property list<string> $scopes
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 */
#[Fillable(['agent_id', 'enrollment_id', 'name', 'key_hash', 'scopes', 'expires_at', 'revoked_at'])]
class AgentApiKey extends Model
{
    public const PREFIX = 'melfs_';

    /** Lifetime of a key minted through an accepted enrollment. */
    public const MINTED_TTL_SECONDS = 86400;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'scopes' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function isLive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function revoke(): void
    {
        $this->revoked_at = now();
        $this->save();
    }

    /**
     * Whether this key may call tools that require the given scope.
     * `all:read` covers every `*:read` scope; `all:write` covers every
     * scope (all read + write).
     *
     * @param  string  $scope  e.g. 'knowledge:read'
     */
    public function allows(string $scope): bool
    {
        $scopes = $this->scopes ?? [];

        if (in_array(ToolCatalog::SCOPE_ALL_WRITE, $scopes, true)) {
            return true;
        }

        return in_array($scope, $scopes, true)
            || (str_ends_with($scope, ':read') && in_array(ToolCatalog::SCOPE_ALL_READ, $scopes, true));
    }

    /**
     * Issue a new scoped key for the agent, returning the plaintext
     * (shown once) — only the hash is stored. Unknown scopes are dropped;
     * a key that ends up with none is refused (fails closed).
     *
     * @param  list<string>  $scopes
     */
    public static function issue(Agent $agent, string $name, array $scopes): string
    {
        $scopes = array_values(array_intersect($scopes, app(ToolCatalog::class)->scopeChoices()));

        if ($scopes === []) {
            throw new \InvalidArgumentException('A scoped key needs at least one known scope.');
        }

        $plaintext = self::PREFIX.Str::random(48);

        static::query()->create([
            'agent_id' => $agent->id,
            'name' => $name,
            'key_hash' => Agent::hashKey($plaintext),
            'scopes' => $scopes,
        ]);

        return $plaintext;
    }

    /**
     * Issue a short-lived scoped key from an accepted enrollment. Each
     * mint is a new row — the plaintext is never stored, so a polling
     * agent gets a fresh key every accepted response.
     *
     * @param  list<string>  $scopes
     */
    public static function issueMinted(
        Agent $agent,
        string $name,
        array $scopes,
        AgentEnrollment $enrollment,
        int $ttlSeconds = self::MINTED_TTL_SECONDS,
    ): string {
        $scopes = array_values(array_intersect($scopes, app(ToolCatalog::class)->scopeChoices()));

        if ($scopes === []) {
            throw new \InvalidArgumentException('A minted key needs at least one known scope.');
        }

        $plaintext = self::PREFIX.Str::random(48);

        static::query()->create([
            'agent_id' => $agent->id,
            'enrollment_id' => $enrollment->id,
            'name' => $name,
            'key_hash' => Agent::hashKey($plaintext),
            'scopes' => $scopes,
            'expires_at' => now()->addSeconds($ttlSeconds),
        ]);

        return $plaintext;
    }

    /**
     * Resolve a live scoped key from its plaintext. Fails closed on a
     * wrong prefix, unknown hash, revoked key, or a disabled agent.
     */
    public static function forKey(string $plaintext): ?self
    {
        if (! str_starts_with($plaintext, self::PREFIX)) {
            return null;
        }

        $key = static::query()
            ->where('key_hash', Agent::hashKey($plaintext))
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();

        if ($key === null || ! $key->agent?->isActive()) {
            return null;
        }

        return $key;
    }
}
