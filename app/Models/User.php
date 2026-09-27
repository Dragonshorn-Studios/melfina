<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * @property string|null $mcp_key_hash
 * @property Carbon|null $mcp_key_issued_at
 * @property array<int, string>|null $mcp_redirect_domains
 * @property string|null $github_token (encrypted at rest)
 * @property string|null $github_repo
 * @property string|null $operator_persona
 */
#[Fillable(['name', 'email', 'password', 'is_owner', 'mcp_key_hash', 'mcp_key_issued_at', 'mcp_redirect_domains', 'operator_persona'])]
#[Hidden(['password', 'remember_token', 'mcp_key_hash', 'github_token'])]
class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Prefix of the install-wide MCP fleet key. Shared MCP client configs
     * send it as the Bearer credential; the agent's own `melf_…` key then
     * picks the persona for the request.
     */
    public const MCP_KEY_PREFIX = 'melfg_';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_owner' => 'boolean',
            'mcp_key_issued_at' => 'datetime',
            'mcp_redirect_domains' => 'array',
            'github_token' => 'encrypted',
        ];
    }

    /**
     * The owner-authored persona every agent sees appended to its
     * instructions — who the human is, how to address them.
     */
    public static function operatorPersona(): ?string
    {
        $persona = static::query()->where('is_owner', true)->value('operator_persona');

        return is_string($persona) && $persona !== '' ? $persona : null;
    }

    /**
     * Generate a new fleet key, store only its hash, and return the
     * plaintext. The plaintext is shown once and never persisted.
     */
    public function rotateMcpKey(): string
    {
        $plaintext = self::MCP_KEY_PREFIX.Str::random(48);

        $this->mcp_key_hash = self::hashMcpKey($plaintext);
        $this->mcp_key_issued_at = now();
        $this->save();

        return $plaintext;
    }

    public static function hashMcpKey(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * Named fleet keys for this owner (multi-key management lives in
     * mcp_fleet_keys; the legacy single hash is migrated into a 'default'
     * row).
     *
     * @return HasMany<McpFleetKey, $this>
     */
    public function mcpFleetKeys(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(McpFleetKey::class);
    }

    /**
     * Named enrollment keys (`melfe_…`) — the rotatable "global secret"
     * secret-less runtimes present to POST /api/enroll.
     *
     * @return HasMany<AgentEnrollKey, $this>
     */
    public function agentEnrollKeys(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AgentEnrollKey::class);
    }

    /**
     * Whether the plaintext credential is a live fleet key — any unrevoked
     * named key, or the legacy single hash (pre-multi-key installs).
     * Fails closed.
     */
    public static function isMcpKey(string $plaintext): bool
    {
        if (McpFleetKey::isLiveKey($plaintext)) {
            return true;
        }

        if (! str_starts_with($plaintext, self::MCP_KEY_PREFIX)) {
            return false;
        }

        return static::query()
            ->where('mcp_key_hash', self::hashMcpKey($plaintext))
            ->exists();
    }
}
