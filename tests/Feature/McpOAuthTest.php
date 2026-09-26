<?php

use App\Models\Agent;
use App\Models\McpPendingGrant;
use App\Models\McpTokenAgent;
use App\Models\User;
use Laravel\Passport\Token;

function pkcePair(): array
{
    $verifier = bin2hex(random_bytes(32));

    return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
}

function registerClient(array $redirectUris = ['http://localhost/callback'])
{
    return test()->postJson('/oauth/register', [
        'client_name' => 'Test MCP Client',
        'redirect_uris' => $redirectUris,
    ]);
}

function mcpCall(string $method, array $params = [], ?string $bearer = null)
{
    $headers = ['Accept' => 'application/json'];

    if ($bearer !== null) {
        $headers['Authorization'] = 'Bearer '.$bearer;
    }

    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => $params,
    ], $headers);
}

function issueOAuthToken(User $owner, Agent $agent, string $clientId): string
{
    [$verifier, $challenge] = pkcePair();
    $redirectUri = 'http://localhost/callback';

    test()->actingAs($owner)->get('/oauth/authorize?'.http_build_query([
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'scope' => 'mcp:use',
        'state' => 'xyz',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]))->assertOk();

    $approve = test()->actingAs($owner)
        ->withSession(['authToken' => session('authToken')])
        ->post(route('passport.authorizations.approve'), [
            'client_id' => $clientId,
            'auth_token' => session('authToken'),
            'agent_id' => $agent->id,
        ]);

    $location = $approve->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $token = test()->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'code' => $query['code'],
        'code_verifier' => $verifier,
    ])->assertOk();

    return $token->json('access_token');
}

it('serves OAuth discovery metadata', function () {
    $resource = $this->getJson('/.well-known/oauth-protected-resource')->assertOk();
    expect($resource->json('resource'))->toBe(url('/'))
        ->and($resource->json('scopes_supported'))->toBe(['mcp:use']);

    $server = $this->getJson('/.well-known/oauth-authorization-server')->assertOk();
    expect($server->json('authorization_endpoint'))->toContain('/oauth/authorize')
        ->and($server->json('token_endpoint'))->toContain('/oauth/token')
        ->and($server->json('registration_endpoint'))->toContain('/oauth/register')
        ->and($server->json('code_challenge_methods_supported'))->toBe(['S256']);
});

it('registers a client via DCR', function () {
    $response = registerClient();

    $response->assertCreated()
        ->assertJsonStructure(['client_id', 'redirect_uris', 'grant_types'])
        ->assertJson(['scope' => 'mcp:use', 'token_endpoint_auth_method' => 'none']);
});

it('rejects redirect URIs outside the allow-list', function () {
    registerClient(['https://evil.example.com/callback'])
        ->assertStatus(400)
        ->assertJson(['error' => 'invalid_redirect_uri']);
});

it('throttles DCR so the clients table cannot be spammed', function () {
    // Smoke check for the route-rebind trick: if a laravel/mcp upgrade ever
    // registers oauth/register after routes/ai.php runs, the unthrottled
    // binding wins back and this test fails on upgrade — that's the signal.
    // A distinct IP keeps the bucket separate from the other tests'.
    $this->withServerVariables(['REMOTE_ADDR' => '10.99.99.99']);

    for ($i = 0; $i < 20; $i++) {
        registerClient()->assertCreated();
    }

    registerClient()->assertTooManyRequests();
});

it('issues a PKCE token bound to the consent-picked agent', function () {
    $owner = User::factory()->owner()->create();
    $agent = Agent::factory()->create();
    $clientId = registerClient()->json('client_id');

    $accessToken = issueOAuthToken($owner, $agent, $clientId);

    expect($accessToken)->toBeString()->not->toBeEmpty();

    $payload = json_decode(base64_decode(strtr(explode('.', $accessToken)[1], '-_', '+/')), true);
    $binding = McpTokenAgent::query()->where('token_id', $payload['jti'])->first();

    expect($binding)->not->toBeNull()
        ->and($binding->agent_id)->toBe($agent->id)
        ->and($binding->client_id)->toBe($clientId);
});

it('lets the OAuth token call MCP tools as the bound agent', function () {
    $owner = User::factory()->owner()->create();
    $agent = Agent::factory()->create();
    $other = Agent::factory()->create();
    $clientId = registerClient()->json('client_id');
    $accessToken = issueOAuthToken($owner, $agent, $clientId);

    $written = mcpCall('tools/call', [
        'name' => 'write_file',
        'arguments' => ['path' => 'oauth.txt', 'content' => 'via oauth'],
    ], $accessToken)->assertOk()->json('result');

    expect($written['isError'] ?? false)->toBeFalse();
    expect($agent->files()->where('path', 'oauth.txt')->exists())->toBeTrue()
        ->and($other->files()->where('path', 'oauth.txt')->exists())->toBeFalse();
});

it('rejects MCP calls after token revocation', function () {
    $owner = User::factory()->owner()->create();
    $agent = Agent::factory()->create();
    $clientId = registerClient()->json('client_id');
    $accessToken = issueOAuthToken($owner, $agent, $clientId);

    $this->postJson('/oauth/revoke', [
        'token' => $accessToken,
        'client_id' => $clientId,
    ])->assertOk();

    mcpCall('tools/list', [], $accessToken)->assertUnauthorized();
});

it('keeps static agent keys working alongside OAuth', function () {
    Agent::factory()->withKey('melf_statickey')->create();

    $result = mcpCall('tools/call', [
        'name' => 'write_file',
        'arguments' => ['path' => 'static.txt', 'content' => 'via key'],
    ], 'melf_statickey')->assertOk()->json('result');

    expect($result['isError'] ?? false)->toBeFalse();
});

it('rejects consent without an agent pick', function () {
    $owner = User::factory()->owner()->create();
    $clientId = registerClient()->json('client_id');
    [, $challenge] = pkcePair();

    $this->actingAs($owner)->get('/oauth/authorize?'.http_build_query([
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => 'http://localhost/callback',
        'scope' => 'mcp:use',
        'state' => 'xyz',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]))->assertOk();

    $this->actingAs($owner)
        ->post(route('passport.authorizations.approve'), [
            'client_id' => $clientId,
            'auth_token' => session('authToken'),
        ])
        ->assertSessionHasErrors('agent_id');

    expect(McpPendingGrant::query()->count())->toBe(0);
});

it('unbinds tokens when the consent grant is removed via console revoke', function () {
    $owner = User::factory()->owner()->create();
    $agent = Agent::factory()->create();
    $clientId = registerClient()->json('client_id');
    $accessToken = issueOAuthToken($owner, $agent, $clientId);

    $this->actingAs($owner)
        ->delete(route('console.clients.destroy', ['client' => $clientId]))
        ->assertRedirect(route('console.clients.index'));

    expect(Token::query()->where('revoked', true)->exists())->toBeTrue()
        ->and(McpTokenAgent::query()->count())->toBe(0)
        ->and(McpPendingGrant::query()->count())->toBe(0);

    mcpCall('tools/list', [], $accessToken)->assertUnauthorized();
});

it('lets the owner manage redirect origins from the console, no env edit', function () {
    $owner = User::factory()->owner()->create();

    // Before the console entry, the origin is refused by DCR.
    registerClient(['https://grok.example.com/cb'])
        ->assertStatus(400)
        ->assertJson(['error' => 'invalid_redirect_uri']);

    $this->actingAs($owner)
        ->post(route('console.mcp.redirect-domains.store'), ['domain' => 'https://grok.example.com'])
        ->assertRedirect(route('console.mcp.connect'));

    expect($owner->fresh()->mcp_redirect_domains)->toBe(['https://grok.example.com']);

    registerClient(['https://grok.example.com/cb'])->assertCreated();
});

it('normalizes and rejects malformed redirect origins', function () {
    $owner = User::factory()->owner()->create();

    // Case + trailing slash collapse to a bare origin; duplicate adds dedupe.
    $this->actingAs($owner)
        ->post(route('console.mcp.redirect-domains.store'), ['domain' => 'HTTPS://App.Example.com:8443/'])
        ->assertRedirect(route('console.mcp.connect'));
    $this->actingAs($owner)
        ->post(route('console.mcp.redirect-domains.store'), ['domain' => 'https://app.example.com:8443'])
        ->assertRedirect(route('console.mcp.connect'));

    expect($owner->fresh()->mcp_redirect_domains)->toBe(['https://app.example.com:8443']);

    foreach (['https://evil.example.com/callback', 'ftp://x', 'https://*', 'not-a-url', 'https://user:pw@h.com', ''] as $bad) {
        $this->actingAs($owner)
            ->post(route('console.mcp.redirect-domains.store'), ['domain' => $bad])
            ->assertSessionHasErrors('domain');
    }

    expect($owner->fresh()->mcp_redirect_domains)->toBe(['https://app.example.com:8443']);
});

it('removes a stored origin and DCR refuses it again', function () {
    $owner = User::factory()->owner()->create(['mcp_redirect_domains' => ['https://gone.example.com']]);

    registerClient(['https://gone.example.com/cb'])->assertCreated();

    $this->actingAs($owner)
        ->delete(route('console.mcp.redirect-domains.destroy'), ['domain' => 'https://gone.example.com'])
        ->assertRedirect(route('console.mcp.connect'));

    expect($owner->fresh()->mcp_redirect_domains)->toBe([]);

    registerClient(['https://gone.example.com/cb'])
        ->assertStatus(400)
        ->assertJson(['error' => 'invalid_redirect_uri']);
});

it('keeps env-provided origins working alongside stored ones', function () {
    User::factory()->owner()->create(['mcp_redirect_domains' => ['https://chatgpt.com']]);

    // Loopback (env default) still allowed…
    registerClient(['http://localhost/cb'])->assertCreated();
    // …plus the stored origin.
    registerClient(['https://chatgpt.com/aidp/callback'])->assertCreated();
});

it('locks the redirect-origin forms to the owner', function () {
    $owner = User::factory()->owner()->create();
    $guest = User::factory()->create(['is_owner' => false]);

    test()->post(route('console.mcp.redirect-domains.store'), ['domain' => 'https://x.example.com'])
        ->assertRedirect();

    $this->actingAs($guest)
        ->post(route('console.mcp.redirect-domains.store'), ['domain' => 'https://x.example.com'])
        ->assertForbidden();

    expect($owner->fresh()->mcp_redirect_domains)->toBeNull();
});
