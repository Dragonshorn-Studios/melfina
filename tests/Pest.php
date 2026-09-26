<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Shared MCP/OAuth test helpers — usable from any feature test file.
 */
use App\Models\Agent;
use App\Models\User;

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
