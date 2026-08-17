# PayFlow — stack rozhodnutí

Účel projektu: 4denní příprava na technický pohovor (Comgate, PHP Developer – AI-first vývoj).
Cíl není jen funkční app, ale schopnost obhájit každé rozhodnutí u pohovoru.

## Framework a jazyk

- **Laravel 10 + PHP 8.1** (composer.json), lokálně běží PHP 8.2.4 (splňuje `^8.1`).
- Vědomě jsme nešli na Laravel 12 / PHP 8.3, i když by to bylo "modernější" — rozhodnutí padlo
  kvůli časovému presu (4 dny) a tomu, že Laravel 10 idiomy jsou pořád plně relevantní pro pohovor.

## Testování

- **PHPUnit** (ne Pest) — skeleton default, formálnější standard.

## Databáze

- **SQL Server (sqlsrv)**, lokální instance uživatele, ne Postgres v Dockeru (původní plán).
- PHP extensions `sqlsrv`/`pdo_sqlsrv` jsou už nainstalované lokálně.
- Důsledek pro concurrency (Den 2 téma): SQL Server defaultně používá lock-based
  READ COMMITTED (ne MVCC jako Postgres), pokud se nezapne READ_COMMITTED_SNAPSHOT.
  `Eloquent::lockForUpdate()` funguje i tady — generuje `WITH (UPDLOCK, ROWLOCK)` místo Postgres `FOR UPDATE`.
- **Oddělená testovací DB: `PaymentGatewayTest`** (stejný server/login jako `PaymentGateway`).
  Původně jsme kvůli času šli bez ní (testy proti stejné DB jako dev data) — ukázalo se to
  jako reálný problém prakticky hned: `RefreshDatabase` dělá `migrate:fresh`, takže každé
  spuštění testů smazalo ručně seedovaná/vytvořená data (demo merchant). Řešeno přes
  `.env.testing` (Laravel ho načte automaticky, když `APP_ENV=testing` - viz `phpunit.xml`).
  **Důležité:** `.env.testing`, pokud existuje, se načte MÍSTO `.env`, ne jako doplněk k němu -
  musí tedy obsahovat kompletní sadu proměnných (zkopírováno z `.env`), ne jen `DB_DATABASE`.

## Infrastruktura

- **Bez Dockeru zatím** — priorita je obsah (payment domain, concurrency, AI copilot),
  ne infra. Docker zůstává jako "umím to, ale záměrně jsem tady prioritizoval jinak" bod pro pohovor.
- **Queue: `database` driver** (ne Redis) — funkční skutečná async fronta (běží přes
  `php artisan queue:work`, podporuje retries/backoff), jen bez Redis závislosti. Lze později
  přepnout na Redis jen změnou `QUEUE_CONNECTION`, bez zásahu do kódu jobů.
- **Mail: `log` driver** — žádný SMTP server lokálně, e-maily nejsou v scope projektu.
- Git repo založený a napojený na `https://github.com/crash963/payment-gateway`.
- `APP_URL=http://127.0.0.1:8000` (ne default `http://localhost`) - musí sedět s portem,
  na kterém skutečně běží `php artisan serve --port=8000`, protože `url()` helper se
  používá pro self-HTTP-cally (fake provider, viz `08-fake-provider-and-webhooks.md`).
  Po každé změně `.env` je potřeba restartovat i `php artisan queue:work`, ne jen `serve`
  - dlouho běžící proces má config načtený v paměti z okamžiku startu.

## Autentizace merchantů

- **Vlastní API-key middleware**, ne Laravel Sanctum — Sanctum je primárně pro
  SPA/user session tokeny, náš use-case je B2B server-to-server API klíč vázaný na Merchanta,
  ne na User model. Bližší reálným payment gateways (Stripe apod.).

## Konvence

- Primary key u API-exponovaných entit: **ULID** (ne auto-increment int, ne UUIDv4).
  Důvod viz `02-merchant-model.md`.
- Peníze: integer v nejmenší jednotce měny (žádný float), Money Value Object.
- V kódu píšeme důkladné WHY-komentáře (proč jsme se rozhodli takhle, ne jen co kód dělá) —
  účel projektu je i studijní materiál na pohovor.
