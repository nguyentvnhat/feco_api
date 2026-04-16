<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AuthTokenIssuer;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AutoRefreshApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $accessTokenValue = $request->bearerToken();
        if (! $accessTokenValue) {
            return $next($request);
        }

        $accessToken = PersonalAccessToken::findToken($accessTokenValue);
        if (! $accessToken || ! $accessToken->can('access')) {
            return $next($request);
        }

        if (! $accessToken->expires_at || ! $accessToken->expires_at->isPast()) {
            return $next($request);
        }

        $refreshTokenValue = (string) ($request->header('X-Refresh-Token') ?? $request->input('refresh_token') ?? '');
        if ($refreshTokenValue === '') {
            return $next($request);
        }

        $refreshToken = PersonalAccessToken::findToken($refreshTokenValue);
        if (! $refreshToken || ! $refreshToken->can('refresh')) {
            return $next($request);
        }

        if ($refreshToken->expires_at && $refreshToken->expires_at->isPast()) {
            $refreshToken->delete();

            return $next($request);
        }

        if (
            $refreshToken->tokenable_id !== $accessToken->tokenable_id
            || $refreshToken->tokenable_type !== $accessToken->tokenable_type
        ) {
            return $next($request);
        }

        $user = $refreshToken->tokenable;
        if (! $user instanceof User) {
            return $next($request);
        }

        $accessToken->delete();
        $refreshToken->delete();

        $tokens = AuthTokenIssuer::issue($user);
        $request->headers->set('Authorization', 'Bearer '.$tokens['token']);

        $response = $next($request);
        $response->headers->set('X-Access-Token', $tokens['token']);
        $response->headers->set('X-Refresh-Token', $tokens['refresh_token']);
        $response->headers->set('X-Token-Expires-At', $tokens['expires_at']->toIso8601String());
        $response->headers->set('X-Refresh-Expires-At', $tokens['refresh_expires_at']->toIso8601String());

        return $response;
    }
}
