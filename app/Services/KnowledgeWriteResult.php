<?php

namespace App\Services;

use App\Models\KnowledgeEntry;

/**
 * Outcome of {@see KnowledgeEntryWriter::upsert()}.
 */
final readonly class KnowledgeWriteResult
{
    /**
     * @param  list<array{entry_id: int, name: string, matched_by?: string, scope?: string}>  $candidates
     */
    public function __construct(
        public bool $ok,
        public ?KnowledgeEntry $entry = null,
        public bool $created = false,
        public bool $conflict = false,
        public ?int $currentVersion = null,
        public ?string $error = null,
        public ?string $code = null,
        public array $candidates = [],
        public ?array $unresolved = null,
    ) {}
}
