<?php

use App\Http\Middleware\ResolveAgentFromKey;
use App\Mcp\Servers\MelfinaServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', MelfinaServer::class)->middleware(ResolveAgentFromKey::class);
