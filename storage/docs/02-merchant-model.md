# Merchant model

Tenant entita — každý Payment/Refund patří jednomu merchantovi. Nese dvě odlišné bezpečnostní
role: `api_key_hash` (my ověřujeme, kdo volá naše API) a `webhook_secret` (my podepisujeme
odchozí webhooky merchantovi).

## Rozhodnutí a proč

- **PK: ULID**, ne auto-increment int, ne UUIDv4. Auto-increment prozrazuje počet záznamů a
  umožňuje enumeration. UUIDv4 jako clustered index PK na SQL Serveru fragmentuje index
  (náhodné inserty → page splits — proto MS nabízí `NEWSEQUENTIALID()`). ULID je time-ordered
  (48b timestamp + 80b random), takže inserty zůstávají zhruba append-only, ID je pořád
  neuhodnutelné a navíc chronologicky řaditelné bez extra sloupce. Trade-off: prozrazuje
  přibližný čas vzniku (na rozdíl od čistě náhodného UUIDv4) — u nás nevadí.

- **`api_key_hash`: SHA-256 (rychlý hash), ne bcrypt.** Bcrypt je záměrně pomalý a solený, takže
  nejde indexovat pro lookup — museli bychom projet všechny merchanty a `Hash::check()` na
  každého (O(n), navíc pomalé, DoS-friendly). Náš klíč je strojově generovaný ~256bitový token,
  ne lidmi vymyšlené heslo — brute-force je neproveditelný nezávisle na rychlosti hashe. Rychlý
  hash + `UNIQUE` index umožňuje `WHERE api_key_hash = ?` v O(1) a DB dump neprozradí použitelný
  klíč. Stejný vzor jako GitHub/Stripe API tokeny.

- **`webhook_secret`: `encrypted` cast, ne hash.** Na rozdíl od API klíče ho potřebujeme zpátky
  v plaintextu (pro výpočet HMAC podpisu odchozích webhooků). Šifrování (AES-256-CBC pod
  `APP_KEY`) chrání DB dump, ale ne únik `APP_KEY` spolu s dumpem — defense in depth, ne záruka.

- **`$fillable = ['name', 'active']`** — `api_key_hash`/`webhook_secret` nejdou nastavit mass
  assignmentem (např. `Merchant::create($request->all())`), generují se výhradně interně přes
  `Merchant::generatePlainApiKey()` / `hashApiKey()`.

- **`$hidden = ['api_key_hash', 'webhook_secret']`** — nikdy se neserializují do JSON/array
  odpovědí, ani omylem.

## Gotcha, na kterou jsem narazil

Eloquent factory (`MerchantFactory`) defaultně instancuje model přes konstruktor
(`new Merchant($attributes)`), který jde přes `fill()` — tedy přes **stejné** `$fillable`
omezení jako mass assignment z HTTP requestu. Bez zásahu by tedy factory tiše zahodila
`api_key_hash`/`webhook_secret` a insert by spadl na NOT NULL constraint. Řešení: přetížení
`newModel()` ve factory, aby model stavěl přes `forceFill()` (factory je důvěryhodný interní
kód, ne HTTP vstup, takže je v pořádku, že guard obchází).

## Testy

- `tests/Unit/MerchantApiKeyTest.php` — čisté funkce (`hashApiKey`, `generatePlainApiKey`),
  žádná DB, žádný Laravel app bootstrap → Unit test.
- `tests/Feature/MerchantModelTest.php` — cokoliv potřebuje DB nebo kontejner (factories
  používají `fake()`, ULID generování přes Eloquent eventy, `RefreshDatabase`) → Feature test.
  Běží proti reálné lokální `PaymentGateway` DB (vědomý trade-off, viz `00-stack-decisions.md`
  — bez oddělené testovací DB kvůli časovému presu).

12 testů, 16 assertions, vše zelené. `./vendor/bin/pint` spuštěn, jedna drobná oprava
(fully qualified strict types v factory docblocku).
