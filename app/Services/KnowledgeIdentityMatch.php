<?php

namespace App\Services;

use App\Models\KnowledgeEntry;

/**
 * One hit from a single resolution layer. Distinct entry ids across
 * layers become ambiguity rather than silent precedence.
 */
final readonly class KnowledgeIdentityMatch
{
    public function __construct(
        public KnowledgeEntry $entry,
        public string $matchedBy,
        public string $scope,
        public string $input,
        public int $priority,
    ) {}

    /**
     * @return array{entry_id: int, name: string, matched_by: string, scope: string}
     */
    public function candidate(): array
    {
        return [
            'entry_id' => $this->entry->id,
            'name' => $this->entry->name,
            'matched_by' => $this->matchedBy,
            'scope' => $this->scope,
        ];
    }
}
