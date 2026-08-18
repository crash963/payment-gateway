# OpenAPI specifikace

`openapi.yaml` v kořeni repa — ručně psaná (ne generovaná z anotací), popisuje
merchant-facing API (`/payments`, `/payments/{payment}/refunds`, `/refunds/{refund}`,
`/copilot/chat`).

**Dodatek (Den 4, po přidání rate limitingu):** doplněn `/copilot/chat` — chyběl od
začátku, přestože jde o plnohodnotný merchant-facing endpoint se stejnou
`auth:merchant` autentizací jako zbytek. Ironie: `searchDocumentation()` tool
Copilota tenhle soubor sám prohledává jako zdroj, ale spec předtím nepopisoval sám
sebe. Zároveň doplněna `429` odpověď na všechny endpointy (viz
`14-rate-limiting.md`) — a při té příležitosti se ukázalo, že `429` bez vlastního
`renderable` v `Handler.php` neodpovídalo jednotné `{error: {code, message}}`
obálce, kterou má zbytek API — opraveno v `app/Exceptions/Handler.php`, ne jen
v dokumentaci (spec musí popisovat skutečné chování, ne aspirační).

**Proč ručně, ne generovaná z kódu:** malý počet endpointů, žádná nová dependency
(balíčky jako `l5-swagger` by přidaly PHP anotace nad každý controller). Nevýhoda:
je potřeba ji ručně udržovat v synchronizaci s kódem — akceptovaný trade-off.

**Proč vůbec:** kromě dokumentace pro merchanty je to explicitně plánovaný zdroj
pravdy pro AI Integration Copilota (Den 4) — `searchDocumentation()` tool bude moct
sahat i sem.

**Záměrně vynechané endpointy:** `POST /api/provider/webhook` a
`POST /api/fake-provider/charge` — nejsou merchant-facing, jsou to interní
infrastrukturní věci mezi PayFlow a (fake) providerem, ne API, které by merchant
volal. Dokumentovat je jako součást merchant-facing kontraktu by bylo matoucí.

**Validace:** YAML syntax ověřena přes Symfony YAML parser (Laravel dependency,
žádný nový nástroj), + skript ověřující, že se všechny `$ref` odkazy skutečně
rozpojí na existující definice. Ručně zkontrolováno proti skutečnému chování
controllerů (např. `Location` hlavička je posílaná i u `200` idempotentního
replay, ne jen u `201` — spec to zprvu měl špatně).

**Neřešeno zatím:** interaktivní UI (Swagger UI/Redoc) nad tím souborem — jen
statický soubor. Lze doplnit později, pokud bude čas.
