<?php

namespace Tests\Feature;

use App\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves the actual DB-level guarantee RefundService::create() depends on: a
 * lockForUpdate() on a payment row genuinely blocks a second, independent connection
 * from reading that same row with its own lockForUpdate() until the first transaction
 * ends. This is the one thing in the whole project that specifically needs two real
 * connections rather than being fakeable/mockable - see config/database.php's
 * "sqlsrv_secondary" entry, added only for this.
 */
class RefundConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Defensive: if an assertion fails mid-test before we get to roll back the
        // manually-opened transaction, make sure it doesn't leak into the next test.
        if (DB::connection('sqlsrv')->transactionLevel() > 1) {
            DB::connection('sqlsrv')->rollBack();
        }

        parent::tearDown();
    }

    public function test_a_second_connection_is_blocked_while_the_first_holds_the_lock(): void
    {
        $payment = Payment::factory()->paid()->create(['amount' => 10000]);

        // Connection 1 (the default connection - already open via RefreshDatabase,
        // this just adds a nested transaction/savepoint on top of it): take the lock
        // and deliberately don't release it yet, simulating a refund request that's
        // mid-flight.
        DB::connection('sqlsrv')->beginTransaction();
        DB::connection('sqlsrv')->table('payments')->where('id', $payment->id)->lockForUpdate()->first();

        // Connection 2: a genuinely separate physical connection - simulates a second,
        // concurrent refund request for the SAME payment. A short LOCK_TIMEOUT means
        // this fails fast with a clear error instead of hanging the test if the lock
        // isn't real.
        DB::connection('sqlsrv_secondary')->statement('SET LOCK_TIMEOUT 500');

        $blocked = false;

        try {
            DB::connection('sqlsrv_secondary')->table('payments')->where('id', $payment->id)->lockForUpdate()->first();
        } catch (QueryException) {
            $blocked = true;
        }

        DB::connection('sqlsrv')->rollBack();

        $this->assertTrue(
            $blocked,
            'A second connection should have been blocked by the first connection\'s lockForUpdate(), but it read the row anyway - RefundService\'s concurrency protection would not actually work.'
        );
    }

    public function test_the_second_connection_can_proceed_once_the_first_transaction_ends(): void
    {
        $payment = Payment::factory()->paid()->create(['amount' => 10000]);

        DB::connection('sqlsrv')->beginTransaction();
        DB::connection('sqlsrv')->table('payments')->where('id', $payment->id)->lockForUpdate()->first();
        DB::connection('sqlsrv')->rollBack(); // releases the lock, same as a completed refund

        DB::connection('sqlsrv_secondary')->statement('SET LOCK_TIMEOUT 500');

        $row = DB::connection('sqlsrv_secondary')
            ->table('payments')
            ->where('id', $payment->id)
            ->lockForUpdate()
            ->first();

        $this->assertNotNull($row);
    }
}
