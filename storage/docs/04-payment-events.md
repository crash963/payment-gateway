# Payment events (audit log)

`payments` má aktuální status pro rychlé čtení, `payment_events` dává auditní historii -
append-only log toho, co se s platbou kdy stalo.

## Schéma

| sloupec | typ | poznámka |
|---|---|---|
| `id` | `char(26)` ULID PK | stejný důvod jako u merchants/payments |
| `payment_id` | FK → `payments.id`, `cascadeOnDelete()` | event bez payment nemá smysl (na rozdíl od `payments.merchant_id`, kde cascade záměrně NENÍ) |
| `type` | `string`, cast na `PaymentEventType` enum | |
| `metadata` | `json`, nullable | flexibilní payload (from/to status, později provider response, HTTP status webhooku) |
| `created_at` | `useCurrent()` | **žádný `updated_at`** - log je immutable |

Index `(payment_id, created_at)` pro `GET /api/payments/{payment}/events` (řazení podle času).

## `PaymentEventType` enum

`PaymentCreated`, `PaymentAuthorized`, `PaymentPaid`, `PaymentFailed`,
`PaymentPartiallyRefunded`, `PaymentRefunded`, `RefundCreated`, `RefundCompleted`.

`PaymentEventType::forStatus(PaymentStatus $status)` mapuje cílový status na event typ -
používá ho `PaymentStateMachine`. Nemá `match` větev pro `Pending` (Pending nikdy není cílem
žádného přechodu v `PaymentStatus::allowedTransitions()`) - pokud by se tam přesto dostal,
je to bug v guardu výš, a `UnhandledMatchError` je správná hlasitá reakce, ne tichý default.

`RefundCreated`/`RefundCompleted` zatím nikde nejsou zapojené - přijdou s `RefundService`
(Den 2), ne se `PaymentStateMachine`.

## Immutabilita

`PaymentEvent::booted()` blokuje `updating`/`deleting` eventy na existující instanci
(`LogicException`). **Limit:** nechrání hromadný `PaymentEvent::where(...)->update()`
přes query builder - ten Eloquent model eventy vůbec nespouští. Plná ochrana by chtěla
DB trigger nebo omezená oprávnění DB uživatele; tohle je pragmatická app-layer verze.

## Zapojení do `PaymentStateMachine`

Každý reálný přechod (ne no-op na stejný status) teď v jedné `DB::transaction()`:
1. uloží nový `status` na `Payment`,
2. zapíše `PaymentEvent` s typem podle `forStatus($to)` a metadaty `['from' => ..., 'to' => ..., ...extra]`.

`PaymentCreated` se zatím nezapisuje nikde - přijde s create-payment flow (REST vrstva),
protože zatím nemáme kód, který by platbu reálně vytvářel mimo testy/factory.

## Testy

44 testů, 96 assertions, vše zelené.
- Unit: `PaymentEventTypeTest` (mapování + `UnhandledMatchError` na `Pending`).
- Feature: `PaymentEventTest` (cast typu/metadat, update/delete guard, cascade delete
  z `payments`), `PaymentStateMachineTest` rozšířený o ověření, že validní přechod
  zapíše event se správnými metadaty a no-op žádný event nezapíše.
