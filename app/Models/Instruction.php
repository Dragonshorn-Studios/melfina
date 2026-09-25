<?php

namespace App\Models;

use Database\Factories\InstructionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Owner instruction layers for one agent — 1:1 with Agent (unique agent_id).
 * `content` is the owner text; `layers` holds optional ordered sub-instructions.
 *
 * @property string $content
 * @property array<int, string>|null $layers
 */
#[Fillable(['agent_id', 'content', 'layers'])]
class Instruction extends Model
{
    /** @use HasFactory<InstructionFactory> */
    use HasFactory;

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return array<int, string>
     */
    public function layersList(): array
    {
        return $this->layers ?? [];
    }

    protected function casts(): array
    {
        return [
            'layers' => 'array',
        ];
    }
}
