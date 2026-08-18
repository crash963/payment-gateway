# První kompletní běh testovací sady — co se rozbilo a proč

`PaymentGatewayTest`/`PaymentGatewayTest2` byly nedostupné většinu Dne 3-4 (SQL Server
recovery problém, viz `00-stack-decisions.md`), takže tohle byl **první** běh celé sady
najednou přes všechny funkce (refundy, merchant webhooky, AI copilot). Odhalilo to čtyři
reálné bugy, tři z nich by v produkci znamenaly opravdu zaseknutý proces.

## 1-4: `RefundConcurrencyTest` se čtyřikrát zasekl (a proč)

Test měl prokázat, že `lockForUpdate()` fakt blokuje druhé spojení - ale sám se
čtyřikrát zaseknul na desítky minut, než fungoval. Každá vrstva byla neviditelná, dokud
jsem to opravdu nespustil:

1. **`SET LOCK_TIMEOUT` na spojení neplatilo pro další příkaz na tom samém "logickém"
   spojení.** Windows ODBC Driver Manager pravděpodobně recykluje fyzické spojení pod
   Laravelovým jménem spojení - session-scoped `SET` není spolehlivé. **Oprava:**
   `WITH (UPDLOCK, ROWLOCK, NOWAIT)` přímo v textu dotazu - není to session state, co by
   šlo ztratit.
2. **"Držitel zámku" nesměl být výchozí `sqlsrv` spojení** (to, které `RefreshDatabase`
   obaluje transakcí) - ruční `rollBack()` na něm je jen rollback k SAVEPOINTu, a SQL
   Server u savepointu **neuvolní zámky řádků** - ty drží až do konce vnější transakce.
   **Oprava:** `sqlsrv_secondary` (nezávislé spojení) jako držitel zámku.
3. **Řádek platby vytvořený přes `Payment::factory()->create()`** běží na výchozím
   spojení = je to necommitnutý INSERT uvnitř `RefreshDatabase` transakce. Pod READ
   COMMITTED ho **jiné spojení vůbec nevidí**, natož zamkne - `sqlsrv_secondary` čekalo
   na řádek, ke kterému se nikdy nemohlo dostat. **Oprava:** merchant + platba se vkládají
   přímo, raw, autocommitujícími příkazy na `sqlsrv_secondary` - opravdu commitnuté,
   viditelné odkudkoliv, ručně uklizené v `tearDown()`.
4. **I ten ruční úklid v `tearDown()` se zasekl** - `WITH (UPDLOCK, NOWAIT)` dotaz z těla
   testu drží update lock až do konce transakce na `sqlsrv` spojení, i po úspěšném
   (neblokovaném) čtení. Můj `tearDown()` mazal řádky PŘED voláním `parent::tearDown()`
   (což dělá skutečný rollback a zámek uvolní). **Oprava:** `parent::tearDown()` volat
   první, ruční úklid až po něm.

**Ponaučení pro pohovor:** tenhle test nešlo "navrhnout správně od začátku" jen čtením
dokumentace o zamykání - každá vrstva problému byla viditelná až při skutečném běhu.
Přesně to je důvod, proč testovat zamykání/concurrency vyžaduje experimentování, ne jen
teoretickou znalost `SELECT FOR UPDATE`.

## 5: Vlastní testovací "bezpečnostní síť" tiše stínila fakes v jednotlivých testech

`tests/TestCase::setUp()` měl `Http::fake()` bez argumentů jako ochranu proti
reálným síťovým voláním (viz `08-fake-provider-and-webhooks.md`). Problém: Laravel
`Http::fake()` **appenduje** nové fakes na konec seznamu a při rozhodování, který fake
použít, bere **první** matchující. Blanketní `Http::fake()` registrovaný v `setUp()`
(běží před každým testem) tak **navždy zastínil** každý pozdější, specifičtější
`Http::fake(['*' => Http::response(...)])` v jednotlivých testech - žádná chyba, jen
tichy default `200` misto toho, co test skutečně chtěl.

Několik testů tak **procházelo z nesprávného důvodu** (default 200 náhodou vyhověl
jejich assertions) - odhalilo to až test, který chtěl `500` (jiný status než default).

**Oprava:** `Http::preventStrayRequests()` místo blanketního `Http::fake()` - stejná
ochrana (nezfakovaný request vyhodí výjimku, nikdy nejde do sítě), ale bez
registrování vlastního fake, co by mohl něco zastínit.

**Vedlejší dopad:** `PaymentServiceTest` a `CreatePaymentTest` ve skutečnosti nikdy
neměly vlastní fake na `/api/fake-provider/charge` (spoléhaly na ten skrytý default) -
musely dostat vlastní `Http::fake()` v `setUp()`.

## Výsledek

**159 testů, 302 assertions, 2 vteřiny, stabilní napříč opakovanými běhy.** Pokrývá
kompletně: Merchant/API-key auth, Payment lifecycle + state machine, idempotence
(payments i refunds), partial refunds + **skutečný** concurrency test, fake provider
(všech 5 scénářů), provider webhook zpracování, merchant webhooky (retry/backoff,
SSRF), AI Integration Copilot (orchestrace, scoping všech nástrojů, human-in-the-loop).
