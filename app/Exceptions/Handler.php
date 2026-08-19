<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
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

        $this->renderable(fn (RefundExceedsRemainingAmountException $e) => response()->json([
            'error' => [
                'code' => 'refund_exceeds_remaining_amount',
                'message' => $e->getMessage(),
            ],
        ], 409));

        // Found in code review: this DomainException's own docblock says "a future API
        // exception handler can catch DomainException to map this to a 409" - that
        // handler never actually got written, so it fell through to a raw 500. Reachable
        // for real: RefundController never checks a payment's status before calling
        // RefundService (only ownership), so refunding a still-pending payment throws
        // this; a conflicting/out-of-order provider webhook (e.g. declined after paid)
        // throws it from ProviderWebhookController too.
        $this->renderable(fn (InvalidStateTransitionException $e) => response()->json([
            'error' => [
                'code' => 'invalid_state_transition',
                'message' => $e->getMessage(),
            ],
        ], 409));

        // Found in code review: OpenAiClient::chat()'s own comment claims "the
        // controller's exception handling turns this into a clean error response" -
        // nothing actually did. A bad/expired OPENAI_API_KEY, an OpenAI rate limit, or
        // an OpenAI outage threw all the way out to Laravel's default 500. 502, not 500
        // or 409: this is specifically an upstream dependency failure, not something
        // wrong with the merchant's own request. Message deliberately doesn't repeat
        // OpenAI's own error body - that's an internal implementation detail, not
        // something to leak to a merchant.
        $this->renderable(fn (RequestException $e) => response()->json([
            'error' => [
                'code' => 'copilot_upstream_error',
                'message' => 'The AI Copilot is temporarily unavailable. Please try again shortly.',
            ],
        ], 502));

        // Covers both a route-model-bound id that doesn't exist at all AND
        // PaymentController::show()'s deliberate `abort(404)` on a Policy denial
        // (Laravel converts ModelNotFoundException to NotFoundHttpException before any
        // renderable runs, so one handler here catches both) - the message is
        // deliberately generic for the same reason unauthenticated()'s is: it must not
        // read differently for "doesn't exist" vs "exists but isn't yours".
        $this->renderable(fn (NotFoundHttpException $e) => response()->json([
            'error' => [
                'code' => 'not_found',
                'message' => 'The requested resource was not found.',
            ],
        ], 404));

        // Without this, a throttled request falls through to Laravel's default JSON
        // exception shape - {"message": "Too Many Attempts."} - breaking the one
        // consistent {"error": {"code", "message"}} envelope every other error on this
        // API uses (see the register() comment above). $e->getHeaders() carries
        // Retry-After (and the X-RateLimit-* headers) set by ThrottleRequests - those
        // must survive onto the response, not just the body shape.
        $this->renderable(fn (ThrottleRequestsException $e) => response()->json([
            'error' => [
                'code' => 'too_many_requests',
                'message' => 'Too many requests. Please slow down and try again.',
            ],
        ], 429, $e->getHeaders()));
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
