<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

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

    /**
     * Always scoped to the caller's own merchant_id in the query itself - there's no
     * "list all payments, then filter to mine" step where a bug could leak someone
     * else's row, because someone else's rows are never fetched in the first place.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::enum(PaymentStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $payments = Payment::query()
            ->where('merchant_id', $request->user()->id)
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return PaymentResource::collection($payments);
    }

    /**
     * Route model binding fetches ANY payment by id first (it has to - the id alone
     * doesn't say which merchant it belongs to), so by the time PaymentPolicy::view()
     * runs, a denial already means "this id exists, just not yours". Deliberately
     * `abort(404)` here instead of `$this->authorize()` (which would 403) - see
     * PaymentPolicy::view() for why: a 403 would itself leak that the id is valid.
     */
    public function show(Request $request, Payment $payment): PaymentResource
    {
        abort_unless(Gate::allows('view', $payment), 404);

        return new PaymentResource($payment);
    }
}
