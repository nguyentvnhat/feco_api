<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonInterface;

class AuthTokenIssuer
{
    /**
     * @return array{token:string,refresh_token:string,expires_at:CarbonInterface,refresh_expires_at:CarbonInterface}
     */
    public static function issue(User $user): array
    {
        $accessTokenExpiresAt = now()->addHours(2);
        $refreshTokenExpiresAt = now()->addDays(30);

        return [
            'token' => $user->createToken('api_access', ['access'], $accessTokenExpiresAt)->plainTextToken,
            'refresh_token' => $user->createToken('api_refresh', ['refresh'], $refreshTokenExpiresAt)->plainTextToken,
            'expires_at' => $accessTokenExpiresAt,
            'refresh_expires_at' => $refreshTokenExpiresAt,
        ];
    }
}
