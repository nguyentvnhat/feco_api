<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e)
    {
        if ($this->isApiPath($request) && $e instanceof AuthenticationException) {
            return $this->apiUnauthenticatedJsonResponse();
        }

        if ($this->isApiPath($request) && ($e instanceof MethodNotAllowedHttpException || $e instanceof NotFoundHttpException)) {
            return $this->apiNotFoundJsonResponse();
        }

        return parent::render($request, $e);
    }

    private function isApiPath(Request $request): bool
    {
        return $request->is('api', 'api/*');
    }

    private function apiNotFoundJsonResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => __('api.errors.not_found'),
            'data' => (object) [],
        ], 404);
    }

    private function apiUnauthenticatedJsonResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => __('api.auth.invalid_credentials'),
            'data' => (object) [],
        ], 401);
    }
}
