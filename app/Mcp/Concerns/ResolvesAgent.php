<?php

namespace App\Mcp\Concerns;

use App\Http\Middleware\ResolveAgentFromKey;
use App\Models\Agent;

/**
 * The agent placed on the request by {@see ResolveAgentFromKey}.
 */
trait ResolvesAgent
{
    protected function agent(): Agent
    {
        $agent = request()->attributes->get('agent');

        assert($agent instanceof Agent);

        return $agent;
    }
}
