<?php

namespace App\Models;

use Database\Factories\AgentMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One directed delivery from a single agent to a single agent. Immutable
 * after creation: body, subject, and attachment snapshots never change;
 * only the recipient's read/acknowledgement timestamps may be set.
 *
 * `sender_name`/`recipient_name` are display snapshots so history stays
 * readable after an agent is renamed or deleted (FKs go null).
 *
 * @property int $sender_agent_id
 * @property int $recipient_agent_id
 * @property string $sender_name
 * @property string $recipient_name
 * @property string|null $subject
 * @property string $body
 * @property Carbon|null $read_at
 * @property Carbon|null $acknowledged_at
 */
#[Fillable(['sender_agent_id', 'recipient_agent_id', 'sender_name', 'recipient_name', 'subject', 'body', 'read_at', 'acknowledged_at'])]
class AgentMessage extends Model
{
    /** @use HasFactory<AgentMessageFactory> */
    use HasFactory;

    public const MAX_SUBJECT_LENGTH = 300;

    public const MAX_BODY_BYTES = 65536;

    public const MAX_ATTACHMENTS = 10;

    public const MAX_ATTACHMENT_NAME_LENGTH = 512;

    public const MAX_ATTACHMENT_BYTES = 65536;

    public const MAX_TOTAL_BYTES = 262144;

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'sender_agent_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'recipient_agent_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AgentMessageAttachment::class);
    }

    /**
     * Whether the agent is a party to this delivery — sender or recipient.
     * Unrelated agents must not even learn the message exists.
     */
    public function involves(Agent $agent): bool
    {
        return $this->sender_agent_id === $agent->id
            || $this->recipient_agent_id === $agent->id;
    }

    public function isRecipient(Agent $agent): bool
    {
        return $this->recipient_agent_id === $agent->id;
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }
}
