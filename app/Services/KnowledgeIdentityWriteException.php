<?php

namespace App\Services;

use RuntimeException;

class KnowledgeIdentityWriteException extends RuntimeException
{
    /**
     * @param  list<array{entry_id: int, name: string, matched_by?: string, scope?: string}>  $candidates
     * @param  array{from?: string, to?: string}|null  $unresolved
     */
    public function __construct(
        string $message,
        public string $code = 'identity_write',
        public array $candidates = [],
        public ?array $unresolved = null,
    ) {
        parent::__construct($message);
    }
}
