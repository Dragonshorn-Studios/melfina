<?php

namespace App\Services;

use App\Models\KnowledgeEntry;
use RuntimeException;

class KnowledgeVersionConflict extends RuntimeException
{
    public function __construct(public ?KnowledgeEntry $entry = null)
    {
        parent::__construct('Knowledge entry version conflict.');
    }
}
