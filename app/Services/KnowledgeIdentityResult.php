<?php

namespace App\Services;

use App\Models\KnowledgeEntry;
use App\Models\KnowledgeIdentityStatus;

/**
 * Typed outcome of {@see KnowledgeIdentityResolver}. Tools branch on
 * status; callers must not treat "first candidate" as a hit.
 */
final readonly class KnowledgeIdentityResult
{
    /**
     * @param  list<array{entry_id: int, name: string, matched_by?: string, scope?: string}>  $candidates
     */
    public function __construct(
        public KnowledgeIdentityStatus $status,
        public ?KnowledgeEntry $entry = null,
        public ?string $matchedBy = null,
        public ?string $scope = null,
        public string $input = '',
        public array $candidates = [],
        public ?string $error = null,
        public ?string $code = null,
    ) {}

    public function found(): bool
    {
        return $this->status === KnowledgeIdentityStatus::Resolved && $this->entry !== null;
    }

    public static function resolved(
        KnowledgeEntry $entry,
        string $matchedBy,
        string $scope,
        string $input,
    ): self {
        return new self(
            status: KnowledgeIdentityStatus::Resolved,
            entry: $entry,
            matchedBy: $matchedBy,
            scope: $scope,
            input: $input,
            code: null,
        );
    }

    public static function notFound(string $input): self
    {
        return new self(
            status: KnowledgeIdentityStatus::NotFound,
            input: $input,
            code: 'not_found',
        );
    }

    /**
     * @param  list<array{entry_id: int, name: string, matched_by?: string, scope?: string}>  $candidates
     */
    public static function ambiguous(string $input, array $candidates): self
    {
        return new self(
            status: KnowledgeIdentityStatus::Ambiguous,
            input: $input,
            candidates: $candidates,
            error: 'Multiple internal entities match this name. Pass entry_id to disambiguate.',
            code: 'ambiguous_reference',
        );
    }

    public static function inconsistent(string $input, string $error): self
    {
        return new self(
            status: KnowledgeIdentityStatus::Inconsistent,
            input: $input,
            error: $error,
            code: 'inconsistent_identity',
        );
    }

    public static function conflict(string $input, string $error): self
    {
        return new self(
            status: KnowledgeIdentityStatus::Conflict,
            input: $input,
            error: $error,
            code: 'identity_conflict',
        );
    }

    public static function closed(KnowledgeEntry $entry, string $input): self
    {
        return new self(
            status: KnowledgeIdentityStatus::Closed,
            entry: $entry,
            matchedBy: 'redirect',
            scope: 'redirect',
            input: $input,
            error: "Entry {$entry->id} was merged; write the canonical entry instead.",
            code: 'redirected_entry',
        );
    }

    /**
     * @return array{input: string, matched_by: string, scope: string}
     */
    public function resolutionPayload(): array
    {
        return [
            'input' => $this->input,
            'matched_by' => $this->matchedBy ?? 'unknown',
            'scope' => $this->scope ?? 'global',
        ];
    }
}
