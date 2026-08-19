<?php

namespace App\Support;

use Illuminate\Database\QueryException;

/**
 * Shared by every "pre-check SELECT, try INSERT, catch the race" idempotency path in
 * this app (PaymentService, RefundService, ProviderWebhookController) - extracted in
 * code review after the identical private method (and its explanatory comment) had
 * been copy-pasted into all three independently.
 *
 * SQLSTATE 23000 is the ANSI-standard "integrity constraint violation" code, returned
 * identically by SQL Server, MySQL and Postgres via PDO - portable, unlike checking a
 * driver-specific numeric error code. Each of this trait's current callers' tables has
 * exactly one UNIQUE constraint besides its ULID primary key, so any 23000 there is
 * safe to treat as that race. A table with a SECOND UNIQUE constraint would make this
 * imprecise for that caller - inspecting the constraint name would then be needed
 * instead of trusting the SQLSTATE alone.
 */
trait DetectsUniqueConstraintViolations
{
    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
