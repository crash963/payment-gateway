<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /**
     * merchant_id comes from $request->user() (the authenticated merchant via the
     * "merchant" guard), never from the request body - StorePaymentRequest doesn't even
     * accept it as a field, so there's no input a client could send to create a payment
     * under a different merchant's account.
     */
    public function store(StorePaymentRequest $request): JsonResponse
    {
        ['payment' => $payment, 'created' => $created] = $this->payments->create(
            $request->user(),
            $request->validated()
        );

        return (new PaymentResource($payment))
            ->response()
            ->setStatusCode($created ? 201 : 200)
            ->header('Location', url("/api/payments/{$payment->id}"));
    }
}
