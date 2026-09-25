<?php

namespace App\Mcp\Concerns;

use App\Http\Middleware\ResolveAgent;
use App\Models\Agent;

/**
 * The agent placed on the request by {@see ResolveAgent}.
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
