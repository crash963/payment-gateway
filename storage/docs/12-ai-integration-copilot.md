# AI Integration Copilot

Den 4. OpenAI Chat Completions API (raw HTTP přes `Http::` facade, konzistentní se
zbytkem projektu - žádné SDK), tool-calling smyčka, human-in-the-loop pro write akce.

## Architektura

```
POST /api/copilot/chat (merchant-authenticated)
  -> CopilotService::chat($merchant, $messages)
     -> system prompt + historie -> OpenAiClient::chat()
     -> pokud model chce tool_calls: spustí je (scoped na $merchant), výsledky nazpět
     -> opakuje (max 5 kol - model, co pořád volá nástroje, nesmí zaseknout request)
     -> jinak vrátí finální textovou odpověď
```

**Bezstavové** - klient posílá celou historii konverzace v každém requestu (stejně jako
OpenAI API samo funguje), server si nic nepamatuje. Human-in-the-loop potvrzení funguje
stejně bezstavově (viz níže) - žádný `CopilotConversation` model, žádná session.

## Bezpečnost: `$merchant` se injektuje z PHP, nikdy z argumentů modelu

`CopilotTool::execute(Merchant $merchant, array $arguments)` — `$merchant` je vždy
autentizovaný merchant z requestu, `$arguments` je nedůvěryhodný vstup od modelu.
I kdyby model v tool-callu poslal cizí `payment_id`, každý nástroj filtruje
`WHERE merchant_id = $merchant->id` PRVNÍ, ne až interpretací argumentů. Tohle je
skutečná bezpečnostní hranice - prompt ("nekoukej na cizí data") by nebyl.

## Nástroje (5 z původních 7 - `getMerchantConfiguration`, `getRecentApiErrors`,
`runIntegrationTest` odloženy jako stretch goal, čas nevyšel)

- `getPayment`, `getPaymentEvents`, `getWebhookDeliveries` - read-only, scoped na merchanta
- `searchDocumentation` - substring search nad `openapi.yaml` + `storage/docs/*.md`,
  **vědomě ne embeddings/vektorové vyhledávání** - náš doc set je malý a psaný
  předvídatelnou terminologií, substring pokrývá realistické dotazy. Limit: neumí
  parafrázovaný dotaz beze společných slov se zdrojovým textem - **ověřeno naživo**:
  dotaz "proč ULID vs SQL Server" nenašel nic (parafráze), přímý dotaz "ULID" ano.
- `resendWebhook` - **jediný WRITE nástroj**, human-in-the-loop

## Human-in-the-loop bez session state

`resendWebhook` má parametr `confirmed` (default `false`). Bez potvrzení vrátí jen
popis navrhované akce (`requires_confirmation: true`), nic neprovede. System prompt
instruuje model, ať `confirmed=true` použije, jen když merchant v konverzaci výslovně
souhlasil. Merchant historii konverzace (včetně nabídnuté akce) pošle zpátky s novou
zprávou "ano, potvrzuji" - model se rozhodne zavolat nástroj znovu s `confirmed=true`.

Znovu-použije **stejný** `DeliverMerchantWebhookJob`, co posílá originální webhooky -
resend se chová identicky (stejné podepisování, stejná SSRF kontrola, stejný
retry/backoff, kdyby selhal znovu).

## Bugy nalezené živým testováním (měli jsme skutečný OpenAI klíč, tak jsem to pořádně zkusil)

1. **`FormRequest::validated()` tiše odřízl `content`** - validoval jsem jen
   `messages.*.role`, ne `content`/`tool_calls`. `validated()` vrací jen pole, na
   která existuje pravidlo - `content` zmizel, OpenAI odpovědělo `400: expected a
   string, got null`. Oprava: přidat `nullable` pravidla i pro `content`,
   `tool_call_id`, `tool_calls` (ověřeno, že `tool_calls` pravidlo typu `array`
   zachová i vnořenou strukturu, ne jen prázdné pole - otestováno přímo přes
   `Validator::make()` v tinkeru, ne jen naslepo).
2. **Vlastní editační chyba** - smazal jsem uzavírací `];` při editaci pravidel,
   PHP parse error. Odhaleno okamžitě přes `php -l` a live request.
3. **`getWebhookDeliveries` nevracel `id` záznamu** - model pak neměl co poslat do
   `resendWebhook`. Bez toho by human-in-the-loop flow nikdy nedošel k akci.

## Živě ověřeno (2026-08-18), se skutečným OpenAI API klíčem

- Obecná konverzace (odpověděl česky, správně popsal svou roli)
- `getPayment` tool-call - správně našel konkrétní platbu, vrátil status/částku
- `getWebhookDeliveries` + `resendWebhook` bez potvrzení - popsal akci, **nic neprovedl**
  (počet záznamů zůstal 1)
- Po explicitním potvrzení - `resendWebhook` s `confirmed=true` - **skutečně dispatchnul
  job**, počet záznamů vzrostl na 2
- `searchDocumentation` - úspěšně našel a použil vlastní dokumentaci k odpovědi

## Demo UI (`GET /copilot`)

`resources/views/copilot.blade.php` - jednoduchý vanilla JS chat interface (bez
frameworku, bez build kroku), místo ručního volání API přes curl/Postman.
Modul-level `conversation` pole drží celou historii (žádný stav na serveru - viz
výše), UI vykresluje tool-call trace (`🔧 nazev({...})`) a pro `resendWebhook`
zvlášť confirm/cancel box, když nástroj vrátí `requires_confirmation`. **Živě
ověřeno v prohlížeči** (2026-08-18): založení platby -> dotaz na webhook -> tool
trace `getWebhookDeliveries` -> návrh resendu s confirm boxem -> kliknutí
"Potvrdit a provést" -> `resendWebhook` s `confirmed=true` -> počet záznamů v DB
vzrostl z 1 na 2. Celý human-in-the-loop flow funguje end-to-end i přes UI, ne
jen přes přímé API volání.

### Známé omezení: stránka `/copilot` nemá vlastní přihlášení

`GET /copilot` je veřejně dostupná (jen `web` middleware, ne `auth`) - stránku
může otevřít kdokoliv, kdo zná URL. Bezpečnost je zajištěná až na úrovni API
volání: `/api/copilot/chat` vyžaduje stejný `auth:merchant` API-key middleware
jako zbytek API, klíč se zadává do pole v hlavičce stránky a posílá se jako
`Authorization: Bearer`. Bez platného klíče žádný request neprojde - stránka
samotná tedy nikoho k ničemu nepustí, jen chybí vlastní login obrazovka/session
pro samotné UI.

**Vědomě přijaté omezení** - dohodnuto, že se v rámci 4denního time-boxu
neřeší, protože jde čistě o demo/prezentační nástroj na pohovor, ne o
produkční feature. Kdyby šlo o skutečné nasazení, řešení by bylo buď (a) UI
schované za standardní web session login (Laravel Breeze/Fortify + merchant
user model), nebo (b) API klíč nikdy nezadávat ručně do pole, ale generovat
server-side po přihlášení a předat do JS jen krátkodobý token. Tohle je
záměrně **jen UX/demo gap, ne bezpečnostní díra v samotné API vrstvě** - páteřní
autorizace (merchant scoping v každém nástroji, viz sekce výš) zůstává plně
funkční a vynucená bez ohledu na to, jak se ke klíči někdo dostane k
formuláři.

## Automatické testy (spuštěné a zelené)

`CopilotServiceTest` (orchestrace: plain odpověď, tool-call round-trip, neznámý
nástroj nespadne, bounded loop na 5 kol), `tests/Feature/Copilot/ToolsTest.php`
(scoping všech nástrojů - cizí merchant nikdy nedostane data), `CopilotChatTest`
(HTTP úroveň, auth, regresní test na `content` bug). `Http::fake()` intercepts
`api.openai.com/*` - žádný automatický test nikdy nezavolá skutečné OpenAI API.
