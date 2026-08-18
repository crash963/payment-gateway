<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Proves the actual DB-level guarantee RefundService::create() depends on: a
 * lockForUpdate() on a payment row genuinely blocks a second, independent connection
 * from reading that same row with its own lockForUpdate() until the first transaction
 * ends. This is the one thing in the whole project that specifically needs two real
 * connections rather than being fakeable/mockable - see config/database.php's
 * "sqlsrv_secondary" entry, added only for this.
 *
 * THREE things went wrong building this test, in order, each only visible by actually
 * running it (this is exactly the kind of test that can't be reasoned about from
 * reading the code alone) - each one hung the test suite for real and had to be killed:
 *
 * 1. `SET LOCK_TIMEOUT` on a connection didn't apply to the very next statement on
 *    that same logical connection. Almost certainly Windows' ODBC Driver Manager
 *    pooling/recycling the physical connection underneath Laravel's logical one, so a
 *    session-scoped SET isn't reliable. Fixed with a raw `WITH (UPDLOCK, ROWLOCK,
 *    NOWAIT)` query instead - NOWAIT is embedded in the query text itself, not
 *    session state that can be silently dropped.
 *
 * 2. Making the RefreshDatabase-wrapped `sqlsrv` connection the LOCK HOLDER doesn't
 *    work either: a manual beginTransaction()/rollBack() on it is only a SAVEPOINT
 *    (it's nested inside RefreshDatabase's own transaction), and SQL Server does NOT
 *    release row locks on a savepoint rollback - only when the outermost transaction
 *    actually ends. Fixed by making `sqlsrv_secondary` (independent of RefreshDatabase)
 *    the lock holder, and only using `sqlsrv` for the NOWAIT probe.
 *
 * 3. Even then: the payment row itself was created via `Payment::factory()->create()`,
 *    which uses the DEFAULT connection - i.e. it's an uncommitted INSERT inside
 *    RefreshDatabase's transaction. Under READ COMMITTED, `sqlsrv_secondary` can't see
 *    (let alone lock) a row that hasn't committed on a DIFFERENT connection - so its
 *    own lockForUpdate() just hung waiting for a row it could never actually reach.
 *    Fixed by inserting the merchant/payment rows directly via raw, autocommitting
 *    statements on `sqlsrv_secondary` itself - genuinely committed, visible to every
 *    connection, cleaned up manually in tearDown() since RefreshDatabase's rollback
 *    (on a different connection) can't touch them.
 *
 * 4. And even THEN: the manual cleanup DELETEs (on sqlsrv_secondary) hung too, at
 *    first - because `WITH (UPDLOCK, NOWAIT)` acquires an update lock even on a
 *    successful read, held until the `sqlsrv` connection's transaction ends. Our own
 *    tearDown() override ran its DELETEs BEFORE calling parent::tearDown() (which is
 *    what actually rolls back that transaction), so the DELETE blocked on a lock our
 *    own test had taken and not yet released. Fixed by calling parent::tearDown()
 *    first, THEN doing the manual cleanup.
 */
class RefundConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private ?string $committedPaymentId = null;

    private ?string $committedMerchantId = null;

    protected function tearDown(): void
    {
        if (DB::connection('sqlsrv_secondary')->transactionLevel() > 0) {
            DB::connection('sqlsrv_secondary')->rollBack();
        }

        // parent::tearDown() FIRST, deliberately - it's what rolls back
        // RefreshDatabase's wrapping transaction on the default `sqlsrv` connection.
        // The test body's `WITH (UPDLOCK, NOWAIT)` probe acquires an update lock that's
        // held until THAT transaction ends, even on a successful (non-blocked) read -
        // running our manual DELETEs (on sqlsrv_secondary) before that rollback made
        // them block on our own still-held lock. Found this the hard way: it hung too.
        parent::tearDown();

        // Manual cleanup: these rows were committed outside RefreshDatabase's
        // wrapped/rolled-back transaction (see createCommittedPaidPayment()), so
        // RefreshDatabase's own rollback above never touches them.
        if ($this->committedPaymentId) {
            DB::connection('sqlsrv_secondary')->table('payments')->where('id', $this->committedPaymentId)->delete();
        }
        if ($this->committedMerchantId) {
            DB::connection('sqlsrv_secondary')->table('merchants')->where('id', $this->committedMerchantId)->delete();
        }
    }

    /**
     * Inserts a merchant + a Paid payment via raw, autocommitting statements on
     * sqlsrv_secondary (no explicit transaction open at this point) - see class doc
     * point 3 for why this needs to be genuinely committed, not built through the
     * factory/default connection.
     */
    private function createCommittedPaidPayment(int $amount): string
    {
        $merchantId = (string) Str::ulid();
        $paymentId = (string) Str::ulid();
        $now = now();

        DB::connection('sqlsrv_secondary')->table('merchants')->insert([
            'id' => $merchantId,
            'name' => 'Concurrency Test Merchant',
            'api_key_hash' => hash('sha256', Str::random(40)),
            'webhook_secret' => Str::random(40),
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::connection('sqlsrv_secondary')->table('payments')->insert([
            'id' => $paymentId,
            'merchant_id' => $merchantId,
            'order_id' => 'order-concurrency-test',
            'amount' => $amount,
            'currency' => 'CZK',
            'status' => PaymentStatus::Paid->value,
            'idempotency_key' => (string) Str::ulid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->committedMerchantId = $merchantId;
        $this->committedPaymentId = $paymentId;

        return $paymentId;
    }

    public function test_a_second_connection_is_blocked_while_the_first_holds_the_lock(): void
    {
        $paymentId = $this->createCommittedPaidPayment(10000);

        // sqlsrv_secondary: takes the lock and deliberately doesn't release it yet,
        // simulating a refund request mid-flight.
        DB::connection('sqlsrv_secondary')->beginTransaction();
        DB::connection('sqlsrv_secondary')->table('payments')->where('id', $paymentId)->lockForUpdate()->first();

        // The default connection simulates a second, concurrent refund request for
        // the SAME payment. NOWAIT fails IMMEDIATELY (SQL Server error 1222) if the
        // row is locked - see class doc point 1 for why this, not SET LOCK_TIMEOUT.
        $blocked = false;

        try {
            DB::connection('sqlsrv')->select(
                'SELECT * FROM payments WITH (UPDLOCK, ROWLOCK, NOWAIT) WHERE id = ?',
                [$paymentId]
            );
        } catch (QueryException) {
            $blocked = true;
        }

        DB::connection('sqlsrv_secondary')->rollBack();

        $this->assertTrue(
            $blocked,
            'A second connection should have been blocked by the first connection\'s lockForUpdate(), but it read the row anyway - RefundService\'s concurrency protection would not actually work.'
        );
    }

    public function test_the_second_connection_can_proceed_once_the_first_transaction_ends(): void
    {
        $paymentId = $this->createCommittedPaidPayment(10000);

        DB::connection('sqlsrv_secondary')->beginTransaction();
        DB::connection('sqlsrv_secondary')->table('payments')->where('id', $paymentId)->lockForUpdate()->first();
        // A REAL rollback (sqlsrv_secondary isn't nested in anything) - genuinely
        // releases the lock, same as a completed refund request would.
        DB::connection('sqlsrv_secondary')->rollBack();

        $rows = DB::connection('sqlsrv')->select(
            'SELECT * FROM payments WITH (UPDLOCK, ROWLOCK, NOWAIT) WHERE id = ?',
            [$paymentId]
        );

        $this->assertNotEmpty($rows);
    }
}
