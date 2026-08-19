# Finální code review

Den 4. Systematický review pass přes celý projekt (diff od `git init` po HEAD) - 8
nezávislých úhlů pohledu (correctness x3, reuse, simplification, efficiency,
altitude, conventions), každý nález ověřen čtením kódu, ne jen věřen na slovo.
10 nálezů, všechny opravené. Pořadí podle závažnosti.

## Korektnost

1. **`InvalidStateTransitionException` se nikdy nevykreslila jako JSON chyba.**
   Vlastní docblock výjimky říkal "a future API exception handler can catch
   DomainException to map this to a 409" - ten handler nikdy nevznikl.
   Reálně dosažitelné: refund na `pending` platbě (`RefundController` kontroluje
   jen vlastnictví, ne status), nebo konfliktní/pozdní provider webhook (`declined`
   po `paid`). Merchant dostal syrovou Laravel 500 místo `{error:{code,message}}`.
   Opraveno: `renderable()` v `Handler.php`, `409 invalid_state_transition`.

2. **`OpenAiClient` nechytal `RequestException`, přestože komentář tvrdil opak.**
   `$response->throw()` s komentářem "the controller's exception handling turns
   this into a clean error response" - nic to nechytalo. Špatný/expirovaný
   `OPENAI_API_KEY`, OpenAI rate limit nebo výpadek = syrová 500. Opraveno:
   `renderable(RequestException::class)`, `502 copilot_upstream_error` (zprávu
   OpenAI merchantovi neukazujeme - interní detail, ne jeho věc).

3. **Copilot duplikoval systémovou zprávu při každém tahu konverzace.**
   `CopilotService::chat()` bez podmínky přidal čerstvou systémovou zprávu před
   `$messages` - ale klient posílá zpátky celé `conversation` pole (včetně systémové
   zprávy z minula) jako příští `messages`. Tah 2 poslal 2 systémové zprávy, tah 3
   tři, bez omezení. Žádný test to nechytil (všechny volaly `chat()` jen s jednou
   user zprávou). Opraveno: `$messages` se před přidáním nové systémové zprávy
   filtruje - odstraní se jakákoliv už přítomná.

4. **`stripos()` falsy-zero bug v `SearchDocumentationTool`.** `! stripos(...)`
   bralo shodu na pozici 0 jako "nenalezeno" (`stripos` vrací `int 0`, `!0` je
   `true`). `openapi.yaml` doslova začíná "openapi: 3.0.3" - dotaz "openapi" byl
   nejsilnější možná shoda, a přesto se přeskočil. Opraveno: `stripos(...) === false`.

5. **Idempotentní replay selhal na stringovém `amount`.** `resolveExisting()`
   porovnávalo `$existing->amount` (int přes Eloquent cast) s `$data['amount']`
   přes `===`. Laravel `integer` validační pravidlo kontroluje jen tvar, ne PHP typ
   - klient poslavší `amount` jako JSON/form string dostal falešný `409
   idempotency_key_conflict` na vlastní nezměněný retry. Stejný bug v
   `PaymentService` i `RefundService`. Opraveno: `(int)` cast při porovnání.

6. **SSRF kontrola zranitelná vůči DNS rebindingu v rámci jednoho pokusu.**
   `UrlSafetyChecker::isSafe()` resolvovalo hostname a validovalo IP, ale skutečný
   `Http::post($url)` o pár řádků dál nechal cURL resolvovat DNS **znovu, nezávisle**
   - doména s krátkým TTL mohla odpovědět veřejnou IP při kontrole a interní/loopback
   adresou o chvíli později při skutečném requestu. Předchozí komentář řešil jen
   TOCTOU mezi uložením URL merchantem a doručením (minuty/hodiny) - ne tuhle užší
   mezeru v rámci jednoho pokusu (sekundy/milisekundy). Opraveno: nová
   `UrlSafetyChecker::resolveValidatedIp()` vrací konkrétní ověřenou IP,
   `DeliverMerchantWebhookJob` na ni request připíchne přes `CURLOPT_RESOLVE` -
   Host hlavička a TLS SNI zůstávají na původním hostname, jen socket se připojí na
   předem ověřenou adresu. Živě ověřeno (viz níže) - webhook doručen, HTTP 200.

7. **Dashboard formuláře bez ochrany proti dvojímu odeslání.** `copilot.blade.php`
   disabluje submit tlačítko po dobu requestu, `dashboard.blade.php` (nedávná
   práce) to nedělalo - a každé odeslání generuje čerstvý `Idempotency-Key`, takže
   dvojklik nebyl deduplikovaný a vytvořil dvě samostatné platby/refundy. Opraveno:
   stejný `disabled`/`finally` vzor jako v Copilot UI.

## Reuse

8. **`isUniqueConstraintViolation()` zkopírovaná 3x** (`PaymentService`,
   `RefundService`, `ProviderWebhookController`), včetně skoro identického
   komentáře v každé kopii. Extrahováno do `App\Support\DetectsUniqueConstraintViolations`
   (trait), použito všemi třemi.

9. **Merchant-scoped payment lookup zkopírovaný 3x** v Copilot toolech
   (`GetPaymentTool`, `GetPaymentEventsTool`, `GetWebhookDeliveriesTool`) - přesně
   ten kód, který je (dle vlastního docblocku `CopilotTool`) skutečnou bezpečnostní
   hranicí "agent nikdy nevidí data jiného merchanta". Extrahováno do
   `FindsMerchantPayment` traitu (`app/Services/Copilot/Tools/Concerns/`).

## Dokumentace

10. **Zastaralý komentář v `routes/api.php`** - tvrdil "60/min/IP" pro globální
    `api` limiter, i když byl v rámci rate-limiting práce zvednutý na 300/min a
    komentář se nikdy neaktualizoval. Opraveno.

## Testy a ověření

10 nových/upravených testů (regresní test pro každý korektnostní nález), celá
sada **186/186 zelených**, Pint čistý. Oprava č. 6 (DNS rebinding pinning) navíc
živě ověřena přes dashboard: nová platba → `pending` → `paid` → webhook doručen
s `http_status: 200`, potvrzeno přímo v DB, ne jen v testu (curl `CURLOPT_RESOLVE`
přes fake HTTP klienta v testech neprojde reálnou síťovou vrstvou, takže automatický
test sám o sobě nedokazuje, že se pinning skutečně použije při reálném volání).
