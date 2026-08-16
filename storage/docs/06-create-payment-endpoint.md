# POST /api/payments

První skutečný endpoint — spojuje auth, `PaymentService`, `PaymentStateMachine`
(nepřímo přes `Payment::createPending()`) a `PaymentEvent` dohromady.

## Idempotence: dvě vrstvy

1. **Pre-check SELECT** podle `(merchant_id, idempotency_key)` — běžná cesta (klient
   opakuje request po timeoutu, dostane stejnou platbu zpět bez zbytečného INSERTu).
2. **`try/catch` kolem INSERTu** — chrání proti skutečnému souběhu: dva requesty mohou
   projít pre-check SELECTem oba s výsledkem "neexistuje", než kterýkoliv stihne
   insertnout. `UNIQUE(merchant_id, idempotency_key)` (viz `payments` migrace) tohle
   při druhém insertu odmítne; chytáme `QueryException`, ověříme `SQLSTATE 23000`
   (ANSI standard pro constraint violation — funguje stejně na SQL Server/MySQL/Postgres,
   na rozdíl od driver-specific error kódu) a dohledáme, co vyhrálo závod.
3. **Konflikt parametrů:** stejný `idempotency_key`, ale jiná částka/měna/order_id →
   `409 idempotency_key_conflict`, ne tichá odpověď s "špatnou" platbou.

Obě větve (pre-check i catch) volají **stejnou** privátní metodu `resolveExisting()` —
skutečný souběh (dva paralelní requesty) jsem v jednom PHPUnit procesu netestoval
(vyžadovalo by víc DB spojení/procesů najednou) — plnohodnotný concurrency test
uděláme u refundů (Den 2), kde je to explicitně požadovaný scénář, a tenhle vzor tím
zpětně ověříme taky.

## `Payment::createPending()` — bug, který live test odhalil

`status` je záměrně mimo `$fillable`. `Payment::create([...bez status...])` necháhá
status na DB defaultu (`->default('pending')`), ale **Eloquent v paměti o DB defaultu neví**
bez `refresh()` — `$payment->status` zůstalo `null` a `PaymentResource` na `->value`
spadlo (`Attempt to read property "value" on null`). Řešení: `Payment::createPending()`
jako druhé "posvěcené" místo (vedle `PaymentStateMachine`) — nastaví `status` na `Pending`
explicitně v PHP přes `forceFill()`, takže model je v paměti správně hned, bez extra
DB roundtripu.

## Druhý bug, který odhalil až ruční curl, ne testy

`postJson()`/`getJson()` v testech automaticky posílají `Accept: application/json`.
Obyčejný `curl` bez `-H "Accept: ..."` tuhle hlavičku (v podobě, kterou Laravel
rozpozná) nemá, takže `$request->expectsJson()` bylo `false`, a jak
`App\Http\Middleware\Authenticate::redirectTo()`, tak fallback v základním
`Handler::unauthenticated()` se pokusily o `route('login')` — která v tomhle čistě
API projektu neexistuje → `RouteNotFoundException` → 500 místo 401.

**Oprava:** appka nemá a nikdy nebude mít login stránku, takže `redirectTo()` vždy
vrací `null` a `Handler::unauthenticated()` (i validation/idempotency renderables)
vždy vrací JSON, bez podmínky na `expectsJson()`. Přidán regresní test
(`test_an_unauthenticated_request_without_an_accept_header_still_gets_json`), který
explicitně simuluje `Accept: */*` misto Laravel-friendly hlavičky.

**Poučení pro pohovor:** automatické testy jsou jen tak dobré, jak dobře simulují
reálného klienta — testovací helpery, které "usnadňují život" (auto-nastavení hlaviček),
zároveň maskují chyby, které by udeřily u méně vychovaného klienta. Ruční ověření
běžícího serveru pořád má smysl i s dobrou testovací sadou.

## Response envelope

`PaymentResource` je zabalený v defaultním Laravel `{"data": {...}}`. `201 Created` +
`Location` header pro nově vytvořenou platbu, `200 OK` (bez nového `Location`u) pro
idempotentní replay — klient tak z HTTP statusu pozná, jestli šlo o novou operaci.

## Autorizace vs autentizace u tvorby platby

`StorePaymentRequest::authorize()` vrací `true` bezpodmínečně — `auth:merchant`
middleware už garantuje přihlášeného merchanta, a vytvoření nové platby se nedotýká
ničích cizích dat (nic k ověření vlastnictví zatím neexistuje). To se liší od
show/update/delete na existujícím Payment, kde bude potřeba Policy (příští krok).
`merchant_id` jde vždy z `$request->user()`, nikdy z těla requestu — pole ani není
validováno, takže klient nemá jak ho poslat.

## Testy

63 testů, 141 assertions. `PaymentServiceTest` (idempotence na úrovni service, bez HTTP),
`tests/Feature/Api/CreatePaymentTest.php` (celý HTTP flow: úspěch, chybějící auth,
spoofing merchant_id, chybějící/špatná validace, idempotentní replay, conflict),
regresní test na chybějící `Accept` hlavičku v `ApiKeyAuthenticationTest`.
