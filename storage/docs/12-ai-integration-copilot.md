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

## Automatické testy (spuštěné a zelené)

`CopilotServiceTest` (orchestrace: plain odpověď, tool-call round-trip, neznámý
nástroj nespadne, bounded loop na 5 kol), `tests/Feature/Copilot/ToolsTest.php`
(scoping všech nástrojů - cizí merchant nikdy nedostane data), `CopilotChatTest`
(HTTP úroveň, auth, regresní test na `content` bug). `Http::fake()` intercepts
`api.openai.com/*` - žádný automatický test nikdy nezavolá skutečné OpenAI API.
