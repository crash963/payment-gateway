<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRefundRequest;
use App\Http\Resources\RefundResource;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class RefundController extends Controller
{
    public function __construct(private readonly RefundService $refunds) {}

    /**
     * $payment comes from route model binding on the URL segment
     * (/payments/{payment}/refunds) - the same 404-not-403 reasoning as
     * PaymentController::show() applies here too: a merchant refunding (or listing
     * refunds for) another merchant's payment must not be able to tell the difference
     * between "doesn't exist" and "exists but isn't yours".
     */
    public function store(StoreRefundRequest $request, Payment $payment): JsonResponse
    {
        abort_unless(Gate::allows('view', $payment), 404);

        ['refund' => $refund, 'created' => $created] = $this->refunds->create(
            $payment,
            $request->validated()
        );

        return (new RefundResource($refund))
            ->response()
            ->setStatusCode($created ? 201 : 200)
            ->header('Location', url("/api/refunds/{$refund->id}"));
    }

    public function index(Request $request, Payment $payment): AnonymousResourceCollection
    {
        abort_unless(Gate::allows('view', $payment), 404);

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $refunds = $payment->refunds()
            ->latest()
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return RefundResource::collection($refunds);
    }

    public function show(Refund $refund): RefundResource
    {
        abort_unless(Gate::allows('view', $refund), 404);

        return new RefundResource($refund);
    }
}
