# API-key autentizace merchantů

## Architektura: vlastní Guard, ne bespoke middleware

`App\Auth\ApiKeyGuard` implementuje `Illuminate\Contracts\Auth\Guard` a je zaregistrovaný
přes `Auth::extend('api-key', ...)` v `AuthServiceProvider::boot()`. `Merchant` implementuje
`Illuminate\Contracts\Auth\Authenticatable` (přes standardní `Illuminate\Auth\Authenticatable`
trait).

**Proč takhle, a ne "middleware co si najde merchanta a dá ho do requestu":** jakmile je to
Guard, `$request->user()`, `auth()->user()`, `Auth::check()`, `$this->authorize(...)`
a Policies (příští krok — merchant nesmí vidět cizí platby) fungují identicky jako u
běžného Laravel uživatele. Žádný vlastní "getCurrentMerchant()" helper nikde v appce.
Ochrana routy je čistě `auth:merchant` middleware, který v Laravelu už existuje —
nepsal jsem žádnou vlastní middleware třídu.

`ApiKeyGuard` **neprochází přes `UserProvider`** (na rozdíl od typického guardu v
`config/auth.php`, který má `'provider' => 'users'`). `UserProvider` abstrahuje "jak
najít uživatele podle credentials" pro víc možných backendů — u nás je jediný backend
`Merchant::findByPlainApiKey()`, který už hashování i lookup řeší sám. Přidávat
`UserProvider` navrch by byla vrstva indirekce bez druhé implementace, kterou by abstrahovala.

## Default guard

`config/auth.php`: `'defaults.guard' => 'merchant'` (místo `web`). Appka nemá žádný
session/login flow pro `User` model, takže `$request->user()` bez uvádění jména guardu
vrací rovnou přihlášeného merchanta všude v kódu.

## Klíč se čte z `Authorization: Bearer <api_key>`

Přes vestavěné `$request->bearerToken()`.

## Konzistentní chybová odpověď

`Handler::unauthenticated()` přepisuje výchozí `{"message": "Unauthenticated."}` na
`{"error": {"code": "unauthenticated", "message": "..."}}` — základ pro jednotný tvar chyb
napříč celým API (validace, 404 apod. dostanou stejný `error.code`/`error.message` tvar
v REST API kroku). Zpráva je stejná pro "chybí klíč", "špatný klíč" i "deaktivovaný
merchant" — neprozrazujeme, jestli klíč vůbec existoval.

## Testy

49 testů, 104 assertions. `ApiKeyAuthenticationTest` definuje dočasné testovací routy
(`/__test/whoami`) přes `Route::middleware(...)` přímo v testu — běžný Laravel vzor pro
testování middleware/guardu předtím, než existují reálné chráněné endpointy. Pokrývá:
validní klíč, default guard (bez explicitního `auth:merchant`), chybějící hlavička,
špatný klíč, deaktivovaný merchant.
