<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    /**
     * `auth:merchant` middleware already guarantees an authenticated merchant before
     * this Form Request even runs - and creating a payment doesn't touch anyone else's
     * data (there's no existing resource to check ownership of yet), so authentication
     * alone is enough here. This is deliberately different from show/update/delete on an
     * existing Payment, which will need a Policy (authorization: not just "are you
     * someone", but "do you own THIS specific resource").
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Idempotency-Key is an HTTP header per the API contract, not a body field - merged
     * in here so it can be validated by the same rules() as everything else instead of
     * checked separately in the controller.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:255'],
            'order_id' => ['required', 'string', 'max:255'],
            // Minor units, matches App\ValueObjects\Money - 0 is rejected too (min:1),
            // a zero-amount "payment" isn't a meaningful operation.
            'amount' => ['required', 'integer', 'min:1'],
            // Format-only check (3 uppercase letters). Not validated against a specific
            // supported-currency whitelist yet - would be a one-line addition
            // (Rule::in([...])) once we decide which currencies the fake provider
            // actually supports.
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            // 'url' only checks format, not where it actually points - real SSRF
            // protection (rejecting URLs that resolve to private/loopback addresses)
            // belongs at the point we actually make an outbound HTTP call to these
            // (Day 3 webhook delivery), not here: the format can be valid today and the
            // DNS record can change before we ever call it (TOCTOU), so validating format
            // at input time is necessary but not sufficient.
            'return_url' => ['nullable', 'url'],
            'callback_url' => ['nullable', 'url'],
        ];
    }
}
