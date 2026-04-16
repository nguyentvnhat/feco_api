<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Lang;

class BaseApiController extends Controller
{
    protected const MONEY_CURRENCY_VND = 'đ';

    /**
     * @param  array<string, mixed>|object  $data
     * @param  array<string, mixed>  $replace
     */
    protected function successResponse(
        string $messageKey,
        array|object $data = [],
        int $status = 200,
        array $replace = []
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $this->translateMessage($messageKey, $replace),
            'data' => $data,
        ], $status);
    }

    /**
     * @param  array<string, mixed>|object  $data
     * @param  array<string, mixed>  $replace
     */
    protected function errorResponse(
        string $messageKey,
        int $status = 400,
        array|object $data = [],
        array $replace = []
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $this->translateMessage($messageKey, $replace),
            'data' => $data,
        ], $status);
    }

    /**
     * @param  array<string, mixed>|object  $data
     * @param  array<string, mixed>  $replace
     */
    protected function createdResponse(
        string $messageKey,
        array|object $data = [],
        array $replace = []
    ): JsonResponse {
        return $this->successResponse($messageKey, $data, 201, $replace);
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private function translateMessage(string $messageKey, array $replace = []): string
    {
        if (!Lang::has($messageKey)) {
            return $messageKey;
        }

        return __($messageKey, $replace);
    }

    protected function formatVietnameseMoney(mixed $amount): string
    {
        return number_format((float) $amount, 0, ',', '.');
    }

    protected function vietnameseMoneyCurrency(): string
    {
        return self::MONEY_CURRENCY_VND;
    }
}
