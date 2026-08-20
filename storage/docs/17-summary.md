## 30sekundová elevator pitch

"PayFlow je simulovaná platební brána v Laravelu, kterou jsem postavil za 4 dny jako
přípravu na tenhle pohovor. Pokrývá celý payment lifecycle — vytvoření platby,
asynchronní zpracování přes fake providera, oboustranné HMAC-podepsané webhooky,
concurrency-safe partial refundy, rate limiting, a AI Copilota s tool-callingem
a human-in-the-loop potvrzením pro write akce. Ke všemu mám testy (186 zelených),
OpenAPI spec, a průběžnou dokumentaci každého rozhodnutí a bugu, na který jsem
narazil — včetně finálního code-review passu, který našel a opravil 10 reálných
problémů."

## Jak je projekt postavený (mapa)

```
Merchant (auth:merchant, API-key guard)
  │
  ├─ POST /api/payments ──────────────► PaymentService (idempotence)
  │                                       └─ Payment::createPending() (status=Pending)
  │                                       └─ InitiatePaymentWithProviderJob (queue, afterCommit)
  │                                            └─ POST /api/fake-provider/charge (žádná auth - viz níže)
  │                                                 └─ SendProviderWebhookJob (queue, HMAC-signed)
  │                                                      └─ POST /api/provider/webhook (HMAC middleware)
  │                                                           └─ PaymentStateMachine::transitionTo(Paid/Failed)
  │                                                                └─ PaymentEvent (audit log)
  │                                                                └─ DeliverMerchantWebhookJob (queue, HMAC, retry/backoff, SSRF)
  │                                                                     └─ POST merchant.webhook_url (nebo demo receiver)
  │
  ├─ POST /api/payments/{id}/refunds ──► RefundService (lockForUpdate, concurrency-safe)
  │                                       └─ stejná PaymentStateMachine cesta výš
  │
  ├─ GET /api/payments/{id}/events, /webhook-deliveries ── read-only audit trail (dashboard)
  │
  └─ POST /api/copilot/chat ──────────► CopilotService (OpenAI tool-calling, human-in-the-loop)

Demo UI: GET /dashboard (celý lifecycle klikáním), GET /copilot (AI chat)
```

---

## 1. Laravel architektura a REST design

- **Form Requests** (validace + `authorize()`), **API Resources** (`{"data": {...}}` envelope),
  **Policies** (`PaymentPolicy::view()` — 404 ne 403, viz níže), **Services** (byznys logika
  mimo controllery), **DI** přes constructor injection (`PaymentService`, `RefundService`
  injektované do controllerů).
- **ULID, ne auto-increment/UUIDv4** na všech API-exponovaných entitách. Auto-increment
  prozrazuje počet záznamů (enumeration). UUIDv4 jako clustered PK na SQL Serveru
  fragmentuje index (náhodné inserty → page splits). ULID = 48b timestamp + 80b random →
  time-ordered inserty (append-only pattern), neuhodnutelné, chronologicky řaditelné bez
  extra sloupce. Trade-off: prozradí přibližný čas vzniku — u nás nevadí.
- **Vlastní `ApiKeyGuard`, ne Sanctum.** Sanctum je pro SPA/user session tokeny; tady je
  B2B server-to-server API klíč vázaný na `Merchant`, ne `User` — bližší reálným payment
  gateways (Stripe apod.). Guard implementuje `Illuminate\Contracts\Auth\Guard`,
  zaregistrovaný přes `Auth::extend()`, takže `$request->user()`, `Auth::check()`,
  Policies fungují identicky jako u běžného Laravel usera — žádný vlastní
  `getCurrentMerchant()` helper.
- **404, ne 403, pro cizí platbu.** Route model binding najde platbu podle ID bez ohledu
  na vlastníka (musí — ID samo neřekne, čí je). `abort_unless(Gate::allows('view', $payment), 404)`,
  ne `$this->authorize()` (který by dal 403 a tím potvrdil, že ID existuje).
- **Jednotná chybová obálka** `{"error": {"code", "message"}}` napříč celým API — i pro
  `429` (throttle) a `502` (Copilot upstream selhání), ne jen pro validaci/404/401.

**Pokud se zeptají "proč zrovna takhle":** u každého z těchto bodů umět říct
alternativu, kterou jsem zvažoval, a proč jsem ji nevybral (viz `00-stack-decisions.md`,
`02-merchant-model.md`, `05-api-key-auth.md`).

---

## 2. Peníze a datové modelování

- **Integer v nejmenší jednotce měny** (haléře), nikdy float — zaokrouhlovací chyby
  float aritmetiky jsou u peněz nepřijatelné.
- `App\ValueObjects\Money` — readonly VO, validuje zápornou částku a formát měny v
  konstruktoru, `add()`/`subtract()` vyhodí výjimku při mismatch měny.

---

## 3. Idempotence

**Dvě vrstvy, ne jedna:**

1. Pre-check `SELECT` podle `(merchant_id, idempotency_key)` — běžná cesta (klient
   opakuje request po timeoutu → dostane stejnou platbu zpět).
2. `try/catch` kolem INSERTu + `UNIQUE(merchant_id, idempotency_key)` constraint —
   chrání proti skutečnému souběhu (dva requesty projdou pre-check SELECTem oba s
   "neexistuje", než kterýkoliv insertne). Zachytává se `QueryException` se
   `SQLSTATE 23000` (ANSI standard, portable napříč DB engine).
3. Konflikt: stejný klíč, jiné parametry → `409 idempotency_key_conflict`.

Obě větve volají stejnou `resolveExisting()` — jedno místo pro "je tohle replay,
nebo konflikt".

**Bug nalezený v code review:** `resolveExisting()` porovnávalo `$existing->amount`
(int přes Eloquent cast) s `$data['amount']` (syrová hodnota z `validated()`) přes
`===`. Laravel `integer` validační pravidlo kontroluje jen _tvar_ čísla, necastuje
typ — klient, co poslal `amount` jako string, dostal falešný `409` na vlastní
nezměněný retry. Opraveno `(int)` castem při porovnání.

**Stejný vzor (unique-constraint = race) je teď sdílený**, ne kopírovaný 3x —
`App\Support\DetectsUniqueConstraintViolations` trait, použitý `PaymentService`,
`RefundService`, `ProviderWebhookController` (další code-review nález).

---

## 4. Concurrency (partial refundy)

**Dva různé mechanismy pro dva různé problémy:**

- Idempotence (duplicitní request) → `UNIQUE` constraint + try/catch (optimistický).
- Ochrana proti přeplacení refundu (kumulativní součet) → `SELECT ... FOR UPDATE`
  (`lockForUpdate()`) na řádku platby (pesimistický) — tady nejde napsat `UNIQUE`
  constraint, protože nejde o "existuje/neexistuje", ale o běžící součet.

`RefundService::create()`: zamkne řádek platby, uvnitř transakce spočítá
`sum(refunds)`, ověří `amount <= remaining`, vloží refund, zavolá
`PaymentStateMachine`. Druhý souběžný request na stejnou platbu čeká na zámek —
vidí aktuální, ne zastaralý součet.

**Jak se to SKUTEČNĚ testuje** (ne jen v hlavě): `RefundConcurrencyTest` používá
**druhé, fyzicky oddělené DB spojení** (`sqlsrv_secondary`), protože zámek chrání
proti _jiné transakci_, ne proti sobě samé. Tenhle test se **4x zasekl** při ladění
(viz `13-full-test-suite-debugging.md`) — skvělý příběh pro pohovor o tom, že
concurrency testování chce experimentování, ne jen teoretickou znalost:

1. `SET LOCK_TIMEOUT` nefungovalo napříč příkazy (Windows ODBC connection pooling) →
   `WITH (UPDLOCK, ROWLOCK, NOWAIT)` přímo v dotazu.
2. Držitel zámku nesměl být default spojení (`RefreshDatabase` ho obaluje transakcí;
   savepoint rollback neuvolní row locky v SQL Serveru) → nezávislé
   `sqlsrv_secondary` jako držitel.
3. Data vytvořená přes `Factory::create()` na defaultním spojení = necommitnutá →
   pod READ COMMITTED je jiné spojení vůbec nevidělo → raw autocommitující insert
   na `sqlsrv_secondary`.
4. `tearDown()` úklid se zasekl na vlastním zámku z těla testu → `parent::tearDown()`
   (skutečný rollback, uvolní zámky) musí proběhnout PŘED ručním úklidem.

---

## 5. Payment state machine

- **Pravidla** (`App\Enums\PaymentStatus::allowedTransitions()`, `canTransitionTo()`,
  `isTerminal()`) odděleně od **efektu** (`App\Services\PaymentStateMachine::transitionTo()`).
  Pravidla jsou čistý enum bez závislostí → unit-testovatelné bez DB.
- Terminální stavy: `Refunded`, `Failed`. Žádné přechody zpátky (`PartiallyRefunded → Paid`
  není povoleno — refund nejde vzít zpět).
- No-op guard: přechod na stejný status (druhý partial refund, co nemění celkový
  status) nezapisuje nový status update, jen `PaymentEvent`.
- Efekt (status update + `PaymentEvent` insert + případný webhook dispatch) je v
  jedné `DB::transaction()` — status bez odpovídajícího audit záznamu by
  `payment_events` udělal nespolehlivým.

**Bug nalezený v code review:** `InvalidStateTransitionException` (vyhozená např.
při refundu na `pending` platbě, nebo konfliktním provider webhooku) se nikdy
nevykreslila jako JSON chyba — vlastní docblock výjimky řekl "budoucí handler ji
namapuje na 409", ten handler ale nikdy nevznikl. Merchant dostal syrovou 500.
Opraveno `renderable()` v `Handler.php` → `409 invalid_state_transition`.

---

## 6. Webhooky — DVA směry, nespleš je

|               | Provider → PayFlow                                                                                | PayFlow → Merchant                                                  |
| ------------- | ------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| Kdo volá koho | (Fake) provider PayFlow informuje o výsledku platby                                               | PayFlow informuje merchanta                                         |
| Endpoint      | `POST /api/provider/webhook`                                                                      | merchantův vlastní `webhook_url`                                    |
| Ověření       | HMAC (`services.fake_provider.webhook_secret`, globální)                                          | HMAC (`merchant.webhook_secret`, per-merchant)                      |
| Idempotence   | `UNIQUE(event_id)` — redelivery musí odpovědět stejně jako poprvé, jinak provider retryuje navždy | není potřeba zápisu (jen doručení)                                  |
| Retry         | na straně (fake) providera                                                                        | `DeliverMerchantWebhookJob`, `$tries=5`, backoff `[10,60,300,900]`s |

**Proč se odpověď z `/fake-provider/charge` nikdy nepoužívá k rozhodnutí o
statusu:** to je jádro celého cvičení. Jediné, co smí měnit status, je
`ProviderWebhookController` přes nezávislé volání od providera. Díky tomu
`TIMEOUT` scénář funguje správně — PayFlow "neví" (klientský timeout), ale webhook
(naplánovaný JEŠTĚ PŘED sleepem) dorazí nezávisle a řekne pravdu.

**"Magic" order_id prefix** (`DECLINE-`, `TIMEOUT-`, `SLOW-`, `DUPLICATE-`,
`INVALID-`) vybírá scénář — stejný vzor jako Stripe testovací čísla karet.

**`webhook_deliveries` — řádek na POKUS, ne na výsledný stav** (jako
`payment_events`) — retry vytváří nové řádky, dává to delivery HISTORY, ne jen
poslední stav.

### SSRF ochrana + DNS-rebinding fix (nejhlubší bezpečnostní bod projektu)

`UrlSafetyChecker::isSafe()` běží před KAŽDÝM pokusem o doručení (ne jen jednou
při uložení URL) — DNS se může mezitím změnit (TOCTOU). Používá `filter_var()`
s `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` (odmítá RFC1918,
loopback, link-local).

**Bug nalezený v code review, opravený nejhlouběji ze všech deseti:** `isSafe()`
resolvovalo hostname a validovalo IP, ale skutečný `Http::post($url)` o pár řádků
dál nechal cURL resolvovat DNS **znovu, nezávisle** — doména s krátkým TTL mohla
odpovědět veřejnou IP při kontrole a interní/loopback adresou o chvíli později
(DNS rebinding). **Oprava:** `UrlSafetyChecker::resolveValidatedIp()` vrací
konkrétní ověřenou IP; `DeliverMerchantWebhookJob` ji připíchne přes
`CURLOPT_RESOLVE` — Host hlavička a TLS SNI zůstávají na původním hostname, jen
socket se připojí na předem ověřenou adresu. **Živě ověřeno přes dashboard**
(ne jen v testu — fake HTTP klient v testech neprochází reálnou síťovou vrstvou,
takže samotný test nedokazuje, že se pinning skutečně použije).

---

## 7. Bezpečnost — souhrn

- **Authn vs authz:** Guard (`auth:merchant` — "kdo jsi") vs Policy
  (`PaymentPolicy::view` — "smíš tohle vidět").
- **`api_key_hash`: SHA-256, ne bcrypt.** Bcrypt je záměrně pomalý/solený → nejde
  indexovat pro `WHERE` lookup (muselo by se projít všechny merchanty). Náš klíč
  je strojově generovaný ~256bitový token, ne lidské heslo — brute-force
  neproveditelný nezávisle na rychlosti hashe. Rychlý hash + `UNIQUE` index = O(1)
  lookup. Stejný vzor jako GitHub/Stripe tokeny.
- **`webhook_secret`: `encrypted` cast, ne hash** — potřebujeme ho zpátky v
  plaintextu pro HMAC výpočet. Chrání DB dump, ne únik `APP_KEY` spolu s dumpem
  (defense in depth, ne záruka).
- **Rate limiting** — 4 limitery (`RouteServiceProvider`):
    - `merchant-api` (60/min, podle merchant ID) — `/payments`, `/refunds`
    - `copilot` (10/min, podle merchant ID) — přísnější, protože stojí reálné peníze (OpenAI)
    - `provider-webhook` (30/min, podle IP) — běží PŘED HMAC ověřením, aby i špatně
      podepsaná záplava byla omezená
    - `api` (300/min, globální defense-in-depth baseline) — **musí být volnější než
      vnitřní limitery**, jinak se sám stane bottleneckem (viz bug níže)
    - **Bug nalezený živě:** `api` a `merchant-api` měly původně stejný limit (60) —
      oba klíčované různě (IP vs merchant), ale sdílená IP jednoho merchanta
      vyčerpáním limitu zablokovala i JINÉHO merchanta na stejné IP. Oprava: vnější
      limiter zvednut na 300.
    - **Druhý bug:** default `api` limiter vypadá, že klíčuje podle `user()->id`, ale
      běží v globální `api` middleware group PŘED `auth:merchant` — `$request->user()`
      je tam vždy `null`, takže klíčuje jen podle IP (mrtvý kód pro autentizované
      routy). `merchant-api`/`copilot` jsou proto route-level middleware, aplikované
      AŽ PO `auth:merchant`.
- **`ApiKeyGuard` cachuje merchanta na instanci** (1 DB dotaz za request) — v
  produkci neškodné (nový kontejner na request, žádný Octane), ale způsobilo
  matoucí test-isolation bug (`auth()->forgetGuards()` potřeba mezi dvěma
  simulovanými merchanty v jednom testu).

---

## 8. Testing strategy

- **Unit** (žádná DB/framework bootstrap): `MerchantApiKeyTest`, `MoneyTest`,
  `PaymentStatusTest`, `PaymentEventTypeTest`, `ProviderScenarioTest` — čisté
  funkce/enum logika.
- **Feature** (DB, kontejner, `RefreshDatabase`): všechno ostatní — services,
  HTTP endpointy, joby, policies.
- **Skutečný concurrency test** s druhým DB spojením (viz sekce 4) — ne mock, ne
  simulace, opravdové paralelní PDO spojení.
- **`Http::fake()` shadowing bug** (viz `13-full-test-suite-debugging.md`):
  blanketní `Http::fake()` v `TestCase::setUp()` **tiše zastínilo** specifičtější
  fakes v jednotlivých testech (Laravel bere PRVNÍ matchující fake, `fake()` volání
  se appendují, ne nahrazují). Několik testů procházelo z nesprávného důvodu.
  Oprava: `Http::preventStrayRequests()` — stejná ochrana (žádný reálný network
  call), bez registrování vlastního fake, co by mohl něco zastínit.
- **186 testů, 454 assertions, ~14s.** (Pozor: číslo v posledním commitu je 186/186
  — pokud se ptají na přesný počet, tohle je aktuální pravda, ne dřívější
  mezikroky typu 159/163.)

---

## 9. AI Integration Copilot — nejdůležitější pro "AI-first" roli

```
POST /api/copilot/chat (merchant-authenticated, 10/min limit)
  -> CopilotService::chat($merchant, $messages)
     -> system prompt + historie -> OpenAiClient::chat() (raw HTTP, žádné SDK)
     -> model chce tool_calls? spustí je (scoped na $merchant), výsledky nazpět
     -> opakuje (max 5 kol)
     -> jinak vrátí finální textovou odpověď
```

- **Bezstavové** — klient posílá celou historii konverzace v každém requestu,
  server si nic nepamatuje (žádný `CopilotConversation` model, žádná session).
- **Bezpečnostní hranice: `$merchant` se injektuje z PHP requestu, NIKDY z
  argumentů modelu.** I kdyby model v tool-callu poslal cizí `payment_id`, každý
  nástroj filtruje `WHERE merchant_id = $merchant->id` PRVNÍ. Prompt ("nekoukej na
  cizí data") by nebyl skutečná hranice — kód je.
- **5 nástrojů:** `getPayment`, `getPaymentEvents`, `getWebhookDeliveries`
  (read-only, scoped), `searchDocumentation` (substring search, vědomě ne
  embeddings — malý doc set, předvídatelná terminologie), `resendWebhook`
  (**jediný WRITE nástroj**).
- **Human-in-the-loop bez session state:** `resendWebhook` má parametr
  `confirmed` (default `false`). Bez potvrzení vrátí jen popis navrhované akce,
  nic neprovede. System prompt instruuje model, ať `confirmed=true` použije jen
  po explicitním souhlasu merchanta v konverzaci — merchant historii (včetně
  nabídnuté akce) pošle zpátky s "ano, potvrzuji", model se rozhodne zavolat
  nástroj znovu.

**Dva bugy nalezené v code review:**

1. **Systémová zpráva se duplikovala každý tah** — `CopilotService::chat()` bez
   podmínky přidávalo čerstvou systémovou zprávu před `$messages`, ale klient
   posílá zpátky celé `conversation` pole (včetně systémové zprávy z minula) jako
   příští `messages`. Tah 2 poslal 2 systémové zprávy, tah 3 tři, bez omezení —
   rostoucí token cost, žádný test to nechytil (všechny testy volaly `chat()`
   jen s jednou user zprávou). Opraveno: `$messages` se před přidáním nové
   systémové zprávy filtruje.
2. **`OpenAiClient` nechytal `RequestException`** — `$response->throw()` s
   komentářem, že to "controller's exception handling" řeší, ale nic to
   nechytalo. Špatný/expirovaný klíč, rate limit, výpadek OpenAI = syrová 500.
   Opraveno: `renderable(RequestException::class)` → `502 copilot_upstream_error`
   (OpenAI zprávu merchantovi neukazujeme — interní detail).
3. Bonus (menší): `SearchDocumentationTool` mělo `stripos()` falsy-zero bug
   (shoda na pozici 0 = "nenalezeno") a merchant-scoped payment lookup byl
   zkopírovaný 3x napříč nástroji — teď sdílený `FindsMerchantPayment` trait
   (`app/Services/Copilot/Tools/Concerns/`).

---

## 10. Demo UI (proč a co ukázat)

- **`/dashboard`** — celý payment lifecycle klikáním: založení platby (s výběrem
  fake-provider scénáře z dropdownu), live-polling detailu (2s), dokud je status
  `pending` (efektní moment — sleduješ naživo pending→paid), refund formulář,
  read-only historie eventů/webhooků.
- **`/copilot`** — AI chat s tool-call trasováním a confirm/cancel UI pro
  `resendWebhook`.
- **Vědomé omezení (obě stránky):** žádné vlastní přihlášení — jen `web`
  middleware, bezpečnost je na úrovni API volání (`auth:merchant` na každém
  endpointu). Přijato jako čistě demo/prezentační trade-off pro pohovor, ne
  bezpečnostní díra v API vrstvě.
- **Bug nalezený v code review:** dashboard formuláře (na rozdíl od copilot chatu)
  neměly ochranu proti dvojímu odeslání — každé odeslání generuje čerstvý
  Idempotency-Key, takže dvojklik vytvořil dvě samostatné platby/refundy misto
  jedné. Opraveno stejným `disabled`/`finally` vzorem jako v Copilot UI.

---

## 11. Vědomá omezení (umět vyjmenovat, ne se za ně omlouvat)

Tohle je síla, ne slabina — ukazuje schopnost vědomě škálovat scope pod časovým
presem a umět to obhájit:

- **Larastan/PHPStan** — rozhodnuto nedělat vůbec (čas šel jinam).
- **Demo UI bez vlastního loginu** — jen API klíč v poli, bezpečnost je na API
  vrstvě.
- **3 stretch Copilot nástroje** (`getMerchantConfiguration`, `getRecentApiErrors`,
  `runIntegrationTest`) — odloženy, zdokumentováno proč.
- **Bez Dockeru** — priorita byla obsah (payment domain, concurrency, AI copilot),
  ne infra. "Umím to, ale záměrně jsem prioritizoval jinak."
- **Queue: `database` driver, ne Redis** — funkční skutečná async fronta bez
  extra závislosti, přepnutelné jen změnou `.env`.
- **`fake-provider/charge`/`demo/webhook-receiver` bez merchant auth** — záměrně,
  jsou to čistě lokální stand-iny za skutečného providera/merchanta, "would never
  exist in a real deployment".

---

## 12. AI-first vývoj — meta úroveň (název pozice to explicitně čeká)

Buď připraven mluvit o **procesu**, ne jen o výsledku:

- **Workflow, který jsem používal:** requirement → analýza existujícího kódu →
  vyjasnění/diskuze trade-offů → implementační plán → (často) diskuze s
  asistentem o alternativách → implementace → automatické testy → statická
  analýza (Pint) → code review pass → živé ověření (browser/curl) → commit.
- **Kde jsem rozhodoval já, kde asistent navrhoval:** architektonická rozhodnutí
  (ULID, custom guard, HMAC boundaries, state machine split) jsem probíral a
  schvaloval explicitně předem, ne nechal "vygenerovat celý projekt najednou" —
  malé kontrolovatelné kroky.
- **Jak jsem ověřoval, že AI návrhy jsou správné, ne jen věrohodně znějící:**
  automatické testy (186 zelených), živé ověření přes browser/curl (ne jen
  testy — např. DNS-rebinding fix testy samy nedokazují, protože fake HTTP klient
  neprochází reálnou síťovou vrstvou), a **finální systematický code review pass**
  (8 nezávislých úhlů pohledu, každý nález ověřen čtením kódu, ne věřen na slovo)
  — který skutečně našel 10 reálných bugů/nedostatků, včetně bezpečnostně
  relevantních (DNS rebinding, chybějící error handling).
- **Konkrétní příklad rigor:** `stripos()` falsy-zero bug — přesně ten typ chyby,
  co "vypadá v pořádku" při rychlém čtení, ale je to klasická PHP past. Ukazuje,
  že i vygenerovaný/napsaný kód potřebuje systematickou verifikaci, ne jen
  důvěru, že "vypadá rozumně".

---

## Rychlá čísla k zapamatování

- **186 testů, 454 assertions**, ~14s běh.
- **5 endpointů merchant-facing REST API** (payments create/list/show, refunds
  create/list/show) + **2 nové read-only** (events, webhook-deliveries) + **1 AI
  endpoint** (`/copilot/chat`) = plná OpenAPI specifikace.
- **10 nálezů z finálního code review passu**, všech 10 opraveno.
- **6 scénářů fake provideru:** Success, Declined, Timeout, SlowResponse,
  DuplicateCallback, InvalidCallback.
- **4 rate limitery**, různé klíčování (merchant ID vs IP) a limity (10/30/60/300
  za minutu).
- **4 dny**, ~17 dokumentačních souborů v `storage/docs/`, každé rozhodnutí a bug
  zdokumentovaný v momentě, kdy se stal.
