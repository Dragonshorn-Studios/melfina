<?php

namespace App\Models;

use Database\Factories\AgentMessageAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable text-file snapshot attached to a message at send time.
 * `name` carries the sender-supplied path; the content is frozen here —
 * later edits to the source VFS file never rewrite delivery history.
 *
 * @property int $agent_message_id
 * @property string $name
 * @property string $content
 */
#[Fillable(['agent_message_id', 'name', 'content'])]
class AgentMessageAttachment extends Model
{
    /** @use HasFactory<AgentMessageAttachmentFactory> */
    use HasFactory;

    public function message(): BelongsTo
    {
        return $this->belongsTo(AgentMessage::class, 'agent_message_id');
    }
}
