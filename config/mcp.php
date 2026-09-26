<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | Origins (scheme://host) that OAuth clients may register as redirect
    | URIs during Dynamic Client Registration. Loopback is always allowed
    | for local clients; add public origins (e.g. https://chatgpt.com) via
    | the MCP_REDIRECT_DOMAINS env var as a comma-separated list. Never
    | use "*" — that opens DCR to arbitrary origins.
    |
    */

    'redirect_domains' => array_filter(array_map('trim', explode(',', env(
        'MCP_REDIRECT_DOMAINS',
        'http://localhost,http://127.0.0.1',
    )))),

    /*
    | The env-only baseline of the allow-list. MergeMcpRedirectDomains
    | unions console-managed origins into 'redirect_domains' per request;
    | this copy is never mutated so the console can render "which of these
    | came from MCP_REDIRECT_DOMAINS" faithfully.
    */
    'redirect_domains_env' => array_filter(array_map('trim', explode(',', env(
        'MCP_REDIRECT_DOMAINS',
        'http://localhost,http://127.0.0.1',
    )))),

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Native desktop clients use private-use URI schemes (RFC 8252) for
    | their OAuth callbacks instead of HTTPS — e.g. claude://, cursor://,
    | vscode://. Only listed schemes may be registered via DCR.
    |
    */

    'custom_schemes' => [
        'claude',
        'cursor',
        'vscode',
        'chatgpt-desktop',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | OAuth authorization server issuer identifier per RFC 8414. Appears
    | in the protected-resource and auth-server metadata. When null it
    | defaults to the app URL.
    |
    */

    'authorization_server' => env('MCP_AUTHORIZATION_SERVER'),

];
