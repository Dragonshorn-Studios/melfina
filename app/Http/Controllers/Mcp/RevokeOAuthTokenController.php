<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Mcp\Auth\RevokeMcpToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RevokeOAuthTokenController extends Controller
{
    public function __invoke(Request $request, RevokeMcpToken $revoke): JsonResponse
    {
        return $revoke->handle($request);
    }
}
