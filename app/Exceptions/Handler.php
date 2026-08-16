<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
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

    /**
     * Overrides the default `{"message": "Unauthenticated."}` shape with the
     * error-envelope convention this API will use consistently (see the REST API step
     * for the rest of it - validation/not-found errors will follow the same
     * `{"error": {"code", "message"}}` shape). Deliberately doesn't distinguish "no
     * key sent" from "key invalid/inactive" in the message - revealing that a key
     * exists but is deactivated (vs. never having existed) is exactly the kind of
     * detail that shouldn't leak to an unauthenticated caller.
     *
     * @param  Request  $request
     */
    protected function unauthenticated($request, AuthenticationException $exception): JsonResponse|Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => [
                    'code' => 'unauthenticated',
                    'message' => 'Invalid or missing API key.',
                ],
            ], 401);
        }

        return parent::unauthenticated($request, $exception);
    }
}
