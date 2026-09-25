<?php

namespace App\Mcp\Auth;

use App\Models\McpTokenAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

class RevokeMcpToken
{
    /**
     * RFC 7009 token revocation: revoke an access token (and its refresh
     * token) or a refresh token (and its access token). Always 200 on
     * success per the spec, even when the token was already unknown.
     */
    public function handle(Request $request): JsonResponse
    {
        $token = $request->input('token');
        $clientId = $request->input('client_id');

        if (! is_string($token) || $token === '') {
            return response()->json([
                'error' => 'invalid_request',
                'error_description' => 'The token parameter is required.',
            ], 400);
        }

        if (! is_string($clientId) || $clientId === '') {
            return response()->json([
                'error' => 'invalid_request',
                'error_description' => 'The client_id parameter is required.',
            ], 400);
        }

        $hint = $request->input('token_type_hint');
        $access = $this->findAccessToken($token);
        $refresh = $this->findRefreshToken($token);

        if ($hint === 'refresh_token') {
            $this->revokeRefresh($refresh, $clientId);
        } elseif ($hint === 'access_token') {
            $this->revokeAccess($access, $clientId);
        } else {
            $this->revokeAccess($access, $clientId);
            $this->revokeRefresh($refresh, $clientId);
        }

        return response()->json(new \stdClass);
    }

    private function findAccessToken(string $value): ?Token
    {
        if (substr_count($value, '.') === 2) {
            $payload = json_decode(
                base64_decode(strtr(explode('.', $value)[1], '-_', '+/')) ?: '',
                true,
            );
            $jti = is_array($payload) ? ($payload['jti'] ?? null) : null;

            if (is_string($jti) && $jti !== '') {
                return Token::query()->whereKey($jti)->first();
            }
        }

        return Token::query()->whereKey($value)->first();
    }

    private function findRefreshToken(string $value): ?RefreshToken
    {
        return RefreshToken::query()->whereKey($value)->first();
    }

    private function revokeAccess(?Token $token, string $clientId): void
    {
        if ($token === null || $token->revoked || ! $this->clientMatches((string) $token->client_id, $clientId)) {
            return;
        }

        $token->revoke();
        $token->refreshToken?->revoke();
        McpTokenAgent::query()->where('token_id', $token->getKey())->delete();
    }

    private function revokeRefresh(?RefreshToken $token, string $clientId): void
    {
        if ($token === null || $token->revoked) {
            return;
        }

        $access = $token->accessToken;

        if ($access instanceof Token && ! $this->clientMatches((string) $access->client_id, $clientId)) {
            return;
        }

        $token->revoke();

        if ($access instanceof Token) {
            $access->revoke();
            McpTokenAgent::query()->where('token_id', $access->getKey())->delete();
        }
    }

    private function clientMatches(string $tokenClientId, string $clientId): bool
    {
        return hash_equals($tokenClientId, $clientId);
    }
}
