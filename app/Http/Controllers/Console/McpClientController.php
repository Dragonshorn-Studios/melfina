<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\McpPendingGrant;
use App\Models\McpTokenAgent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Laravel\Passport\Client;

class McpClientController extends Controller
{
    /**
     * List every OAuth client registered via DCR, the agent its tokens bind
     * to, and how many live tokens it holds.
     */
    public function index(): View
    {
        $clients = Client::query()
            ->orderBy('name')
            ->get()
            ->map(function (Client $client): array {
                $grant = McpPendingGrant::query()->where('client_id', $client->getKey())->first();
                $tokens = McpTokenAgent::query()->where('client_id', $client->getKey())->count();

                return [
                    'client' => $client,
                    'agent' => $grant?->agent,
                    'token_count' => $tokens,
                ];
            });

        return view('console.clients.index', ['clients' => $clients]);
    }

    /**
     * Revoke a client's access (revoke its tokens + refresh tokens, drop the
     * consent grant and agent bindings, then delete the client itself).
     */
    public function destroy(Client $client): RedirectResponse
    {
        DB::transaction(function () use ($client): void {
            foreach ($client->tokens()->get() as $token) {
                $token->revoke();
                $token->refreshToken?->revoke();
            }

            McpTokenAgent::query()->where('client_id', $client->getKey())->delete();
            McpPendingGrant::query()->where('client_id', $client->getKey())->delete();

            $client->delete();
        });

        return redirect()->route('console.clients.index');
    }
}
