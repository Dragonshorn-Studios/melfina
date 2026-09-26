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
 */
#[Fillable(['name', 'email', 'password', 'is_owner', 'mcp_key_hash', 'mcp_key_issued_at', 'mcp_redirect_domains'])]
#[Hidden(['password', 'remember_token', 'mcp_key_hash'])]
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
        ];
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
     * Whether the plaintext credential is a live fleet key. Fails closed.
     */
    public static function isMcpKey(string $plaintext): bool
    {
        if (! str_starts_with($plaintext, self::MCP_KEY_PREFIX)) {
            return false;
        }

        return static::query()
            ->where('mcp_key_hash', self::hashMcpKey($plaintext))
            ->exists();
    }
}
