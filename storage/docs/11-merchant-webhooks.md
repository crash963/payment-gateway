# Merchant webhooks

Uzavírá poslední velký kus z Dne 3: PayFlow teď aktivně informuje merchanta o změnách
platby (`payment.paid`, `payment.failed`, `payment.authorized`,
`payment.partially_refunded`, `payment.refunded`), s HMAC podpisem, retry/backoff a
auditní historií doručení.

## Architektura

```
PaymentStateMachine::transitionTo()
  -> zapíše PaymentEvent (jako doteď)
  -> pokud PaymentEventType::webhookEventName() !== null:
     DeliverMerchantWebhookJob::dispatch($event->id)->afterCommit()

DeliverMerchantWebhookJob (queue, $tries=5, backoff [10, 60, 300, 900] s)
  -> cíl: payment.callback_url ?? merchant.webhook_url (žádný z obou = no-op, ne chyba)
  -> UrlSafetyChecker::isSafe() - SSRF guard, PŘED každým pokusem (ne jen jednou)
  -> HMAC-SHA256 přes merchant.webhook_secret, header X-PayFlow-Signature
  -> každý pokus (úspěch i selhání) zapíše WebhookDelivery řádek PŘED tím, než případně
     vyhodí výjimku - takže historie existuje, i když request skončí chybou
  -> non-2xx/timeout -> vyhodí výjimku -> Laravel queue retry mechanismus (ne ruční)
  -> po vyčerpání pokusů -> failed_jobs (existující tabulka ze skeletonu, konečně použitá)
```

## `webhook_deliveries` — jeden řádek na POKUS, ne na event

Stejný vzor jako `payment_events`/`provider_webhook_events` (immutable append-only log),
ale explicitně **na pokus**, ne na výsledný stav - proto `attempt` sloupec a proto retry
vytváří nové řádky, nepřepisuje existující. Tak dostaneme delivery HISTORY (jak žádá
zadání), ne jen "poslední známý stav".

## SSRF ochrana — konečně implementovaná, ne jen zmíněná

`UrlSafetyChecker` běží těsně před KAŽDÝM pokusem o doručení (ne jen jednou při uložení
URL) - DNS se může mezitím změnit (TOCTOU). Používá `filter_var()` s
`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` (vestavěná PHP validace, žádné
ruční psaní CIDR rozsahů). `config('webhooks.allow_private_urls')` je escape hatch pro
lokální vývoj (`.env`/`.env.testing` = `true`, `.env.example` = `false` bezpečný default).

**Proč na tom záleží:** zákeřný/kompromitovaný merchant účet by mohl nastavit
`webhook_url` na např. `http://169.254.169.254/...` (cloud metadata endpoint) a použít
PayFlow jako proxy k místům v síti, kam by se sám nedostal - PayFlow by za něj request
poslal ze svého serveru.

## Demo receiver — druhá strana, aby to šlo předvést kompletně lokálně

`DemoMerchantWebhookReceiverController` (`POST /api/demo/webhook-receiver`) simuluje
merchantův server - ověří HMAC podpis (potřebuje `merchant_id` z payloadu, aby věděl,
proti kterému secretu ověřovat - proto ho payload obsahuje, na rozdíl od veřejného
`PaymentResource`, který `merchant_id` schválně skrývá), zaloguje přijetí, a řídí se
stejnou "magic" konvencí jako `ProviderScenario` - `WEBHOOKFAIL-` prefix v `order_id`
vynutí `500`, ať jde předvést retry/backoff naživo. Demo merchant (seeder) má
`webhook_url` nastavenou na tenhle receiver automaticky.

## Bug, na který jsem narazil (a druhá lekce o dlouho běžících procesech)

`WebhookDelivery` model neměl `const CREATED_AT = 'sent_at';` - migrace pojmenovala
sloupec `sent_at` (podle zadání), ale Eloquent bez téhle override zkouší zapisovat do
`created_at`, který neexistuje. Objeveno živě (`SQLSTATE[42S22]: Invalid column name
'created_at'`), opraveno.

**Druhá past:** po opravě modelu se chyba dál objevovala - `php artisan queue:work` je
dlouho běžící proces, který si PHP třídy načte JEDNOU při startu a **nevidí změny v
kódu za běhu**, stejně jako jsme dřív zjistili u změn `.env`. Musel jsem worker
restartovat, ne jen opravit soubor. Obecná poučka pro pohovor: dlouho běžící procesy
(queue workery, ale i FPM pooly v produkci) potřebují explicitní restart/reload po
nasazení nového kódu - tohle přesně řeší např. `php artisan queue:restart` (spíš než
ruční kill) v produkčním deploy skriptu.

## Živě ověřeno (2026-08-18)

- Úspěšné doručení: `payment.paid` → `webhook_deliveries` řádek `http_status=200,
  successful=1`, demo receiver zalogoval `signature_valid: true`.
- Retry/backoff: `WEBHOOKFAIL-` platba → pokus 1 (500) v čase T, pokus 2 (500) v T+12s
  (odpovídá `backoff()[0] = 10`) - mechanismus prokazatelně funguje, zbytek (60s/300s/900s)
  necháno doběhnout na pozadí, ne čekáno v konverzaci.

## Automatické testy (spuštěné a zelené)

`UrlSafetyCheckerTest` (Feature - potřebuje `config()`, proto ne Unit),
`DeliverMerchantWebhookJobTest` (úspěch/selhání/timeout/no-url/callback_url override/SSRF
refuse), `PaymentStateMachineTest` rozšířený o assert na dispatch, `DemoMerchantWebhookReceiverTest`.
`tests/Unit/PaymentEventTypeTest.php` rozšířený o `webhookEventName()`.

**Bonus nález při prvním kompletním běhu sady:** `DeliverMerchantWebhookJobTest`'s test
na `500` odpověď odhalil, že vlastní testovací "ochrana" (blanketní `Http::fake()` v
`tests/TestCase.php`) tiše stínila specifičtější fakes v jednotlivých testech - viz
`13-full-test-suite-debugging.md`.
