<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One agent self-enrollment: a runtime's report-in (`remote_id` + claimed
 * `name`) the owner accepts or revokes from the console. `remote_id` is
 * the agent's own stable id (e.g. a session/conversation id) and
 * de-dupes repeat reports. `scopes` freezes the allowlist the owner
 * chose at accept time so every later mint is deterministic.
 *
 * @property int $id
 * @property string $remote_id
 * @property string $name
 * @property string $status
 * @property int|null $agent_id
 * @property list<string>|null $scopes
 */
#[Fillable(['remote_id', 'name', 'status', 'agent_id', 'scopes'])]
class AgentEnrollment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REVOKED = 'revoked';

    protected function casts(): array
    {
        return ['scopes' => 'array'];
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return HasMany<AgentApiKey, $this>
     */
    public function mintedKeys(): HasMany
    {
        return $this->hasMany(AgentApiKey::class, 'enrollment_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    /**
     * Whether the claimed name already belongs to an agent — the console
     * flags it so the owner knows accepting binds to an existing brain.
     */
    public function claimedAgentExists(): bool
    {
        return Agent::query()->where('name', $this->name)->exists();
    }

    /**
     * Accept the report-in: bind to an existing agent with the same name,
     * or create one (no `melf_` key — minted credentials only). Runs in a
     * transaction with the caller's agent resolution.
     *
     * @param  list<string>  $scopes
     */
    public function accept(Agent $agent, array $scopes): void
    {
        $this->forceFill([
            'status' => self::STATUS_ACCEPTED,
            'agent_id' => $agent->id,
            'scopes' => $scopes,
        ])->save();
    }

    /**
     * Revoke acceptance (or decline a pending report): flips the status
     * and revokes every live key minted from this enrollment.
     */
    public function revoke(): void
    {
        $this->status = self::STATUS_REVOKED;
        $this->save();

        $this->mintedKeys()->whereNull('revoked_at')->get()
            ->each(fn (AgentApiKey $key) => $key->revoke());
    }
}
