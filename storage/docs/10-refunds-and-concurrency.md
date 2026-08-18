# Partial refunds + concurrency

## Dva různé mechanismy pro dva různé problémy

- **Idempotence plateb/refundů** (duplicitní request) → `UNIQUE` constraint + try/catch
  na race (optimistický přístup - viz `06-create-payment-endpoint.md`).
- **Ochrana proti přeplacení refundu** (kumulativní součet) → `SELECT ... FOR UPDATE`
  (`lockForUpdate()`) na řádku platby (pesimistický přístup) - tady žádný `UNIQUE`
  constraint nejde napsat, protože nejde o "existuje/neexistuje", ale o běžící součet.

`RefundService::create()`: zamkne řádek platby (`Payment::where('id', ...)->lockForUpdate()`),
uvnitř transakce spočítá `sum(refunds)`, ověří `amount <= remaining`, vloží refund,
zavolá `PaymentStateMachine` (na `PartiallyRefunded` nebo `Refunded` podle toho, jestli
je součet roven celé částce). Druhý souběžný request na stejnou platbu čeká na zámek,
takže vidí už aktuální (ne zastaralý) součet.

## Refund je synchronní (na rozdíl od plateb)

Žádný fake-provider round-trip pro refundy - vytvoří se a je hotovo v jedné transakci.
Rozhodnutí kvůli scope (hlavní lekce je zamykání, ne distribuované systémy) a času.
`PaymentEventType::RefundCreated` (byl definovaný už dřív, teď poprvé zapojený).

## Skutečný concurrency test - dvě reálná DB spojení

`RefundConcurrencyTest` přidává druhé pojmenované DB spojení (`sqlsrv_secondary` v
`config/database.php`, stejná DB/přihlašovací údaje, ale fyzicky oddělené PDO
připojení). Test: spojení 1 zamkne řádek a nezacommitne, spojení 2 s krátkým
`SET LOCK_TIMEOUT` se pokusí zamknout stejný řádek - musí dostat timeout chybu, jinak
zámek reálně nefunguje. Druhý test ověří, že po uvolnění zámku (commit/rollback)
spojení 2 projde bez problému.

**Proč na tohle nestačilo jedno spojení:** zámek chrání proti *jiné transakci*, ne proti
sobě samé - potřebovali jsme opravdu dva fyzicky oddělené PDO connections, ne dvě volání
na stejném.

## Stav ověření (2026-08-18)

- **Živě ověřeno** proti `PaymentGateway` (dev DB): partial refund → `partially_refunded`,
  doplacení → `refunded`, přeplacení → `409 refund_exceeds_remaining_amount` se
  smysluplnou zprávou, idempotentní replay → `200` stejný refund, `GET .../refunds` list
  funguje se stránkováním.
- **Automatické testy spuštěné a zelené** (viz `13-full-test-suite-debugging.md` pro
  detaily o tom, co bylo potřeba opravit v `RefundConcurrencyTest`, než prošel):
  `RefundServiceTest`, `RefundPolicyTest`, `RefundConcurrencyTest`,
  `tests/Feature/Api/CreateRefundTest.php`, `tests/Feature/Api/ListAndShowRefundsTest.php`.
