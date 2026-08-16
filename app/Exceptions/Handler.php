<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
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

        // Same {"error": {"code", "message"}} envelope as unauthenticated() below -
        // this is the seed of one consistent error shape across the whole API, not a
        // one-off. `details` carries the field-level messages Laravel's default
        // ValidationException response already computes (`$e->errors()`); we're only
        // changing the outer envelope, not re-deriving the per-field messages.
        //
        // Deliberately unconditional (not gated behind `$request->expectsJson()`):
        // every route in this app is an API route, there is no web/login UI at all, so
        // there's no such thing as a request that legitimately wants an HTML error page
        // here. Gating on expectsJson() previously meant a client that didn't send an
        // explicit `Accept: application/json` header (plain curl, e.g.) fell through to
        // Laravel's HTML/redirect error handling instead - which, on top of being the
        // wrong response for a JSON API client, crashed outright for unauthenticated()
        // (see that method's comment). The test suite never caught this because
        // postJson() always sets that header.
        $this->renderable(fn (ValidationException $e) => response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'The given data was invalid.',
                'details' => $e->errors(),
            ],
        ], 422));

        $this->renderable(fn (IdempotencyKeyConflictException $e) => response()->json([
            'error' => [
                'code' => 'idempotency_key_conflict',
                'message' => $e->getMessage(),
            ],
        ], 409));
    }

    /**
     * Overrides the default `{"message": "Unauthenticated."}` shape with the
     * error-envelope convention this API will use consistently (see the REST API step
     * for the rest of it - not-found errors will follow the same
     * `{"error": {"code", "message"}}` shape). Deliberately doesn't distinguish "no
     * key sent" from "key invalid/inactive" in the message - revealing that a key
     * exists but is deactivated (vs. never having existed) is exactly the kind of
     * detail that shouldn't leak to an unauthenticated caller.
     *
     * Always JSON, never `parent::unauthenticated()`'s redirect-to-login fallback -
     * see the class-level comment on register() for why: this app has no login page to
     * redirect to at all.
     */
    protected function unauthenticated($request, AuthenticationException $exception): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'unauthenticated',
                'message' => 'Invalid or missing API key.',
            ],
        ], 401);
    }
}
