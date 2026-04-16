<?php

namespace Modules\Auth\App\Http\Controllers;

use App\Http\Controllers\BaseApiController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends BaseApiController
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $login = $validated['login'];
        $password = $validated['password'];

        $user = User::query()
            ->where('phone', $login)
            ->orWhere('email', $login)
            ->first();

        if (!$user || !Hash::check($password, (string) $user->password)) {
            return $this->errorResponse('api.auth.invalid_credentials', 401, (object) []);
        }

        $token = $user->createToken('api')->plainTextToken;

        return $this->successResponse('api.auth.login_success', [
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? null,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return $this->successResponse('api.auth.logout_success', (object) []);
    }
}

