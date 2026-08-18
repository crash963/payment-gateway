# Rate limiting

Den 4 (bezpečnostní pass). Bez rate limitingu má API dvě reálné díry: brute-force na
API klíč (nic nebrání zkoušet tisíce klíčů na `/payments`) a DoS/vyčerpání zdrojů -
u Copilota konkrétně **skutečné utrácení OpenAI kreditu** za každý request.

Použit vestavěný Laravel `throttle` middleware (`RateLimiter::for()`, sliding-window
counter přes cache) - nejde o nic psané od nuly, jen správně nakonfigurované.
`CACHE_DRIVER=file` (viz `00-stack-decisions.md`) stačí, throttle přes něj funguje
atomicky, Redis není potřeba.

## Limitery (`RouteServiceProvider::boot()`)

| Limiter | Limit | Klíč | Kde |
|---|---|---|---|
| `api` (Laravel default) | 60/min | `user()?->id ?: ip()` | globálně, celá `api` middleware group |
| `merchant-api` | 60/min | `merchant->id` | `/payments`, `/refunds` |
| `copilot` | 10/min | `merchant->id` | `/copilot/chat` |
| `provider-webhook` | 30/min | IP | `/provider/webhook` |

## Bug v defaultním `api` limiteru, na který jsme narazili

Skeletonový `RateLimiter::for('api', ...)` klíčuje `$request->user()?->id ?: $request->ip()`
a vypadá to, že chrání podle merchanta. Ve skutečnosti je to **mrtvý kód** pro
autentizované routy - `throttle:api` sedí v globální `api` middleware group
(`RouteServiceProvider::$this->routes()`), která běží **před** jakýmkoliv route-level
middlewarem včetně `auth:merchant`. V okamžiku, kdy se ten limiter vyhodnocuje,
`$request->user()` je vždy `null` (merchant ještě není autentizovaný) - takže se vždy
použije IP, ne merchant id, přestože kód vypadá, že řeší obojí.

Proto `merchant-api`/`copilot` jsou samostatné limitery aplikované jako **route-level**
middleware, až po `auth:merchant` v pořadí (`Route::middleware('auth:merchant')->group(
fn () => Route::middleware('throttle:merchant-api')->group(...))`) - tam už
`$request->user()` skutečně je resolvnutý Merchant. Defaultní `api` limiter necháváme
běžet dál jako obecný IP-based baseline pro celé `/api` (defense in depth), jen mu
už nevěříme, že řeší merchant-specific throttling.

## Bug č. 2, nalezený živě testem: sdílená IP mezi merchanty

Test `test_a_different_merchant_has_its_own_independent_limit` odhalil skutečnou
chybu, ne jen teoretickou: `api` a `merchant-api` měly původně **stejný** limit
(60/min). Global `api` limiter je vždy klíčovaný podle IP (viz bug výše), takže po
vyčerpání 60 requestů jedním merchantem byl na stejné IP zablokovaný i **úplně jiný
merchant** - vnější limiter se stal skutečným, ne jen teoretickým, bottleneckem, a
popřel tak celý smysl per-merchant klíčování u `merchant-api`. V testech je to
extrémní (všichni sdílí `127.0.0.1`), ale reálně stejný problém nastane pro merchanty
za stejným NAT/shared hostingem.

**Oprava:** vnější defense-in-depth vrstva musí být záměrně volnější než vnitřní,
kterou obaluje, jinak se stane náhodným bottleneckem místo záchranné sítě. `api`
zvednuto na 300/min - dost volné, aby normální provoz více merchantů ze stejné IP
neprobudil, ale pořád chytí hrubé zneužití celého API z jednoho místa.

## `provider-webhook` běží PŘED ověřením HMAC podpisu

`Route::middleware(['throttle:provider-webhook', 'verify.provider.signature'])` -
záměrné pořadí. I záplava requestů se **špatným** podpisem musí být omezená, ne jen
ty s platným - jinak by rate limiting sám o sobě nechránil proti nejjednodušší formě
útoku (spamovat endpoint bez znalosti secretu).

## Vědomě vynechané: `/fake-provider/charge`, `/demo/webhook-receiver`

Obě routy mají v komentáři `routes/api.php` explicitní poznámku "would never exist in
a real deployment" - jsou to čistě lokální demo stand-iny (simulovaný externí
processor, simulovaný merchant server). Mají jen globální `api` default (60/min/IP),
žádný dedikovaný limiter. Přidat jim vlastní přísnější limit by bylo cvičení, ne
reálná ochrana - v produkčním nasazení by tyhle endpointy vůbec neexistovaly.

## `429` a jednotný formát chyb

`app/Exceptions/Handler.php` má už od REST API kroku jednotnou obálku chyb
`{"error": {"code", "message"}}` pro validaci/idempotenci/404/401. Bez vlastního
handleru pro `ThrottleRequestsException` by `429` spadl do Laravelího defaultu
`{"message": "Too Many Attempts."}` - jiný tvar, jiné jméno klíče, žádný `code`.
Doplněn `renderable(ThrottleRequestsException::class)` se stejnou obálkou
(`code: too_many_requests`) a `$e->getHeaders()` předané do response, aby přežila
hlavička `Retry-After` (tu nastavuje `ThrottleRequests` middleware, ne my).

## Vedlejší nález: `ApiKeyGuard` a testování více merchantů v jednom testu

Test na nezávislost limitů mezi merchanty (`test_a_different_merchant_has_its_own_independent_limit`)
nejdřív spadl úplně jinak, než se čekalo - druhý merchant dostal `429` s `limit=60,
remaining=0`, přestože měl mít čerstvý bucket. Příčina není v rate limiteru:
`ApiKeyGuard` cachuje resolvnutého merchanta **na instanci** (jeden DB dotaz za
request), a Laravel `AuthManager` tuhle instanci cachuje po dobu života kontejneru.
V reálném nasazení to nevadí (nový kontejner na request, žádný Octane). Ale
`$this->getJson()` v testech běží ve **stejném** kontejneru napříč voláními v jedné
test metodě - takže druhý `Authorization` header se nikdy skutečně nepřeautentizoval,
guard pořád vracel prvního merchanta. Opraveno voláním `auth()->forgetGuards()`
mezi oběma merchanty v testu; zdokumentováno i přímo v `ApiKeyGuard`, ať to
příště nikoho nezaskočí. Ryze testovací artefakt, ne bezpečnostní díra v produkci -
ale ukazuje, jak snadno se to dá splést, kdyby se projekt někdy přesunul na
persistent-worker model (Octane).

## Testy

`tests/Feature/RateLimitingTest.php` - pro každý dedikovaný limiter (`merchant-api`,
`copilot`, `provider-webhook`) ověřuje, že request číslo `limit+1` dostane `429`
s hlavičkou `Retry-After`, zatímco requesty do limitu `429` nedostanou.
