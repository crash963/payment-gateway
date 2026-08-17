# Fake Payment Provider + provider webhook

Uzavírá smyčku Pending → Paid/Failed. Předtím jsme přeskočili rovnou na refundy (Den 2
plán), ale to nedávalo smysl bez tohohle kroku - viz diskuze v chatu.

## Architektura (async, ne synchronní)

```
POST /api/payments (merchant)
  -> Payment (Pending) + PaymentEvent(PaymentCreated) v transakci
  -> InitiatePaymentWithProviderJob::dispatch($payment->id)->afterCommit()
  -> odpověď merchantovi HNED (201/200), bez čekání na providera

InitiatePaymentWithProviderJob (queue)
  -> HTTP POST /api/fake-provider/charge (real HTTP self-call, Http::timeout(2))
  -> odpověď ignorujeme obsahově (viz níže proč)

FakeProviderController::charge()
  -> vybere ProviderScenario podle order_id prefixu
  -> SendProviderWebhookJob::dispatch(...) HNED (nezávisle na dalším sleep())
  -> TIMEOUT: sleep(3) - přesahuje klientův 2s timeout
  -> SLOW_RESPONSE: sleep(1) - pod timeoutem, jen pomalu

SendProviderWebhookJob (queue)
  -> HMAC-signed HTTP POST /api/provider/webhook
  -> DUPLICATE_CALLBACK: dispatchne se 2x se STEJNÝM event_id
  -> INVALID_CALLBACK: podepíše špatným secretem

ProviderWebhookController::handle()
  -> VerifyProviderWebhookSignature middleware (hash_equals, ne ===)
  -> idempotentní zápis (UNIQUE event_id, stejný try/catch vzor jako u idempotency)
  -> PaymentStateMachine::transitionTo(Paid|Failed)
```

## Klíčové rozhodnutí: odpověď na `/fake-provider/charge` se NIKDY nepoužívá k rozhodnutí o statusu

To je jádro celého cvičení. `InitiatePaymentWithProviderJob` ignoruje obsah odpovědi
(úspěch i selhání) - jediné, co smí měnit status platby, je `ProviderWebhookController`
přes vlastní, nezávislé volání od providera. Díky tomu TIMEOUT scénář funguje správně:
PayFlow "neví", jestli se to povedlo (klientský timeout), ale webhook (naplánovaný
JEŠTĚ PŘED sleepem) dorazí nezávisle a řekne pravdu. Ověřeno živě - platba s
`TIMEOUT-` prefixem skončila `paid`, i když synchronní volání selhalo.

## Dvě různé HMAC důvěryhodnostní hranice

Provider→PayFlow webhook (`services.fake_provider.webhook_secret`, jeden globální secret)
je jiná hranice než PayFlow→merchant webhook (`merchant.webhook_secret`, per-merchant).
Nikdy nepoužívat jeden pro druhý.

## "Magic" order_id konvence pro výběr scénáře

`DECLINE-`, `TIMEOUT-`, `SLOW-`, `DUPLICATE-`, `INVALID-` prefix, jinak SUCCESS - stejný
vzor jako Stripe testovací čísla karet. Žádné nové API pole.

## Skutečné bugy, na které jsem narazil (a jak byly odhalené)

1. **`InitiatePaymentWithProviderJob::dispatch(...)->afterCommit()` se v testech
   spouští SKUTEČNĚ synchronně** (mylně jsem čekal, že `RefreshDatabase`'s obalující
   transakce, která se nikdy nezacommitne, to zablokuje - nezablokovala). Bez faku dělal
   reálný HTTP self-call na `http://localhost/api/fake-provider/charge`, kde nic
   neposlouchalo -> ~1.2s cURL timeout na test. Řešení: `Http::fake()` jako výchozí v
   `tests/TestCase::setUp()` - žádný test nesmí dělat reálný síťový požadavek, tečka.
   Test suite: 14.87s → 1.38s.
2. **`APP_URL=http://localhost`** (port 80) vs. reálný `php artisan serve --port=8000`
   - `url()` helper generoval špatnou adresu, self-call vždy padal na timeout i naživo.
   Opraveno na `APP_URL=http://127.0.0.1:8000`.
3. **Queue worker běžící na pozadí nenačte změnu `.env`** - dlouho běžící proces má
   config v paměti z okamžiku startu. Po každé změně `.env`, která ovlivňuje queue joby,
   je potřeba `php artisan queue:work` restartovat, ne jen `php artisan serve`.

## Provozní poznámka pro ruční testování

Aby platba reálně přešla na `Paid`/`Failed`, musí běžet **dva** procesy zároveň:
```bash
php artisan serve --port=8000
php artisan queue:work --tries=1
```
Bez `queue:work` zůstane každá nová platba navždy v `Pending` - nic ji neposune.

`php artisan serve` je jednovláknový, takže `TIMEOUT`/`SLOW_RESPONSE` scénář (sleep
uvnitř `FakeProviderController`) na chvíli zablokuje i ostatní požadavky do appky. Pro
lokální demo OK, u produkčního/concurrent serveru (Octane apod.) by to neplatilo.

## Testy

100 testů, 205 assertions (44 nových). `ProviderScenarioTest` (mapování prefixů, pure),
`InitiatePaymentWithProviderJobTest` (`Http::fake()` - správný payload, no-op na
non-Pending platbu/neexistující platbu, graceful `ConnectionException`),
`FakeProviderChargeTest` (`Queue::fake()` - scénář → job payload, DUPLICATE stejný
event_id, INVALID flag), `SendProviderWebhookJobTest` (`Http::fake()` - správný podpis,
špatný podpis u INVALID), `ProviderWebhookTest` (validní signature+success/declined,
špatná signature odmítnuta 401 a nemění status, duplicate event_id zpracován jen jednou,
neznámé payment_id 404).

Ověřeno i živě proti běžícímu serveru + queue workeru - všech 5 scénářů (SUCCESS,
DECLINE, TIMEOUT, DUPLICATE_CALLBACK, INVALID_CALLBACK) prošlo se správným výsledným
statusem.
