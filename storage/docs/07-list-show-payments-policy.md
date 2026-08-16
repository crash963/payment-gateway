# GET /api/payments, GET /api/payments/{payment}, PaymentPolicy

## 404, ne 403, pro cizí platbu

`GET /api/payments/{id}` na platbu jiného merchanta vrací **404**, ne Laravel-default **403**.
Route model binding platbu najde podle ID bez ohledu na vlastníka (musí - ID samo o sobě
neříká, komu patří), takže v okamžiku, kdy `PaymentPolicy::view()` řekne "ne", už víme,
že ID existuje. `403` by tuhle informaci potvrdil útočníkovi zdarma. `404` neprozrazuje
nic navíc, ať je situace "neexistuje" nebo "existuje, ale není tvoje" - v `PaymentController::show()`
je to `abort_unless(Gate::allows('view', $payment), 404)`, ne `$this->authorize()`
(který by vyhodil `AuthorizationException` → 403).

`Handler::renderable(NotFoundHttpException)` dává oběma případům (nonexistentní ID i
zamítnutá Policy) stejnou generickou zprávu - ze stejného důvodu jako `unauthenticated()`.

## `PaymentPolicy`

Jen `view()`. Žádné `viewAny`/`create`/`update`/`delete`/`restore`/`forceDelete` stuby -
`viewAny` nedává smysl (list je vždy pre-scoped v query, ne filtrovaný item-by-item přes
Policy), `create` autorizaci neřeší (viz `StorePaymentRequest::authorize()`), a operace
update/delete/restore na platbě v tomhle API vůbec neexistují (status se mění jen přes
`PaymentStateMachine`/refundy). Přidáme, až bude reálný důvod.

Zaregistrována explicitně v `AuthServiceProvider::$policies` (Laravel by ji našel i
konvencí, ale explicitní mapování je o řádek dražší a nulově nejednoznačné).

## `GET /api/payments`

Vždy `where('merchant_id', $request->user()->id)` v query - žádný krok "najdi všechno,
pak profiltruj", kde by šlo omylem cizí řádek propustit. Filtrování přes `?status=`
(validováno `Rule::enum(PaymentStatus::class)` → 422 na neplatnou hodnotu), stránkování
přes `paginate()` (`?per_page=`, max 100 - nad limit je to validační chyba, ne tiché
oříznutí). Řazení `latest()` (nejnovější první) - default, nebylo explicitně požadováno.

Response wrapping (`data`/`links`/`meta`) je automatický od `PaymentResource::collection()`
nad paginátorem - navazuje na `data` obálku z create-payment kroku.

## Config drobnost

`php artisan make:policy --model=X` potřebuje `auth.guards.merchant.provider`, aby
uhodl typ "user" modelu pro stub - i když `ApiKeyGuard` provider za běhu vůbec nepoužívá.
Doplněno (`config/auth.php`: `providers.merchants` → `Merchant::class`) jen kvůli tooling
podpoře, ne proto, že by ho guard potřeboval.

## Testy

76 testů, 164 assertions. `PaymentPolicyTest` (policy přímo, bez HTTP),
`tests/Feature/Api/ShowPaymentTest.php` (vlastní platba 200, cizí platba **404 ne 403**,
neexistující ID 404, **malformed ID 404 ne 500** - `HasUlids::resolveRouteBindingQuery()`
odmítne non-ULID hodnotu dřív, než by šla do DB dotazu, `ListPaymentsTest` (scoping,
pagination meta, filtrování, validace `status`/`per_page`).

Ověřeno i živě proti běžícímu serveru (list, show vlastní platby, show neexistující →
404 s naším JSON tvarem, ne crash).
