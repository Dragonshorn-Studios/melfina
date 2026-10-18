<?php

namespace App\Services;

use App\Models\KnowledgeLink;
use App\Models\KnowledgeObservation;

/**
 * Outcome of {@see KnowledgeGraph::connect()}. Tools and the console branch
 * on `ok`; unresolved endpoints never write a link.
 */
final readonly class KnowledgeConnectResult
{
    /**
     * @param  array{from?: string, to?: string}|null  $unresolved
     * @param  array{from?: list<array{entry_id: int, name: string}>, to?: list<array{entry_id: int, name: string}>}|null  $ambiguous
     */
    public function __construct(
        public bool $ok,
        public ?KnowledgeLink $link = null,
        public ?KnowledgeObservation $observation = null,
        public bool $created = false,
        public ?array $unresolved = null,
        public ?string $error = null,
        public ?string $code = null,
        public ?array $ambiguous = null,
    ) {}
}
