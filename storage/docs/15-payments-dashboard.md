# Payments dashboard

Den 4 (poslední kus před pohovorem). Cíl: umět naživo předvést celý payment
lifecycle - založení, async zpracování providerem, webhook, refund - kliknutím v
prohlížeči, ne psaním curl/Postman requestů na pohovoru.

## Architektura

Stejný vzor jako `/copilot` ([12-ai-integration-copilot.md](12-ai-integration-copilot.md)):
jedna Blade stránka (`resources/views/dashboard.blade.php`), vanilla JS, žádný build
krok. Žádný nový stav na serveru - stránka je jen tenký klient nad existujícím
merchant API, přihlášený přes `Authorization: Bearer` s API klíčem zadaným v hlavičce
stránky (předvyplněný demo merchant `pf_demo_local_testing_key`, stejný jako u
Copilota - viz `DatabaseSeeder`).

## Dva nové read-only endpointy

`GET /payments` (list), `GET /payments/{id}` (detail), `POST /payments` (založení),
`GET+POST /payments/{id}/refunds` už existovaly. Chyběly dva pohledy na audit trail
jedné konkrétní platby - Copilot k nim měl vlastní tooly, ale žádný REST endpoint
pro ně neexistoval:

- `GET /payments/{payment}/events` — `PaymentEventController`, timeline
  `payment_events` (audit log, Den 2 téma)
- `GET /payments/{payment}/webhook-deliveries` — `WebhookDeliveryController`,
  historie doručení merchant webhooku

Oba: stejný `PaymentPolicy::view` scoping a 404-ne-403 vzor jako zbytek API, žádná
paginace (vždy malá, ohraničená kolekce vázaná na jednu platbu, ne na celou historii
merchanta jako `GET /payments`). `WebhookDeliveryController` jde přes
`$payment->paymentEvents()->pluck('id')`, ne přímo přes `merchant_id` -
`WebhookDelivery` patří k `PaymentEvent`, ne přímo k `Payment` (viz ten model), takže
scoping přes `merchant_id` by mohl teoreticky vrátit doručení jiné platby stejného
merchanta. Otestováno explicitně (`WebhookDeliveriesTest::test_deliveries_for_a_different_payment_of_the_same_merchant_are_excluded`).

**Vědomě read-only:** znovu-poslání webhooku zůstává výhradně přes Copilota
(human-in-the-loop `resendWebhook` tool) - dashboard tuhle write akci záměrně
neduplikuje, jen na ni odkazuje.

## Scénáře fake provideru přímo ve formuláři

`ProviderScenario::fromOrderId()` čte "magickou" předponu `order_id`
(`DECLINE-`, `TIMEOUT-`, `SLOW-`, `DUPLICATE-`, `INVALID-`). Dashboard má dropdown,
který tu předponu sám přidá - merchant/divák u pohovoru nemusí předpony znát ani
psát, jen vybere scénář ze seznamu.

## Live "watch it happen" demo moment

Po založení platby se dashboard rovnou přepne na její detail a **polluje**
`GET /payments/{id}` každé 2s (rekurzivní `setTimeout`, ne `setInterval` - vyhne se
překrývajícím se requestům, když by jeden trval déle než interval), dokud status
zůstává `pending`. Efekt: založíš platbu, sleduješ naživo, jak ji queue worker
async pošle fake provideru a webhook potvrzení ji přepne na `paid`/`failed`.

**Vyžaduje běžící `php artisan queue:work`** - bez něj `InitiatePaymentWithProviderJob`
nikdy neproběhne a platba zůstane `pending` navždy (poll neskončí). Stejná
podmínka jako pro `resendWebhook` v Copilotovi.

## Vědomé omezení: žádné vlastní přihlášení stránky

Stejné jako `/copilot` (viz `12-ai-integration-copilot.md`) - `GET /dashboard` nemá
vlastní auth, jen `web` middleware. Bezpečnost je na úrovni API volání
(`auth:merchant` na každém endpointu). Přijato jako čistě demo/prezentační trade-off,
ne bezpečnostní díra v API vrstvě samotné.

## Testy

`PaymentEventsTest`, `WebhookDeliveriesTest` - scoping (vlastní/cizí merchant,
404-ne-403), chronologické řazení, a explicitní test na join přes `payment_events`
(ne `merchant_id`) popsaný výš.
