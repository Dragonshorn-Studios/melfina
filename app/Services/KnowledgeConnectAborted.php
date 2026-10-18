<?php

namespace App\Services;

use RuntimeException;

/**
 * Thrown inside a connect transaction so Laravel rolls back stub entities
 * when the second endpoint cannot be resolved.
 */
class KnowledgeConnectAborted extends RuntimeException
{
    public function __construct(public KnowledgeConnectResult $result)
    {
        parent::__construct($result->error ?? 'Knowledge connect aborted.');
    }
}
