# Kadi API

REST/JSON backend for the **Kadi** online card game. Phase 1 scope: user
accounts, a coin ledger, and match-result recording. A separate Node game
service runs the realtime game and calls this API to identify users and award
coins when a match finishes.

- **Framework:** Laravel 13 (PHP 8.4)
- **Auth:** Laravel Sanctum **personal access tokens** (Bearer / mobile flow — not the SPA cookie flow). Registration is **enabled**.
- **Database:** SQLite (file-based, zero-config) at `database/database.sqlite`.

## Running locally

```bash
composer install
cp .env.example .env        # if you don't already have a .env
php artisan key:generate    # if APP_KEY is empty
touch database/database.sqlite
php artisan migrate
php artisan serve           # http://127.0.0.1:8000
```

Run the test suite:

```bash
php artisan test
```

Run the end-to-end smoke test (boots a temp server, exercises the full flow):

```bash
./smoke.sh
```

## Configuration

| Env var          | Default                        | Purpose                                                            |
|------------------|--------------------------------|-------------------------------------------------------------------|
| `DB_CONNECTION`  | `sqlite`                       | Dev database. The sqlite file lives at `database/database.sqlite`. |
| `INTERNAL_SECRET`| `dev-kadi-secret-change-me`    | Shared secret the Node service sends as `X-Internal-Secret` to call internal endpoints. **Change in production.** |

`INTERNAL_SECRET` is wired through `config/services.php` as
`services.internal.secret`.

## Endpoints

All app endpoints are under the `/api` prefix. The internal endpoint lives at the
root under `/internal`.

### Public

#### `POST /api/register`
```bash
curl -X POST http://127.0.0.1:8000/api/register \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"name":"Alice","email":"alice@example.com","password":"password123"}'
```
Returns `201`:
```json
{ "token": "1|plain-text-token...", "user": { "id":1, "name":"Alice", "email":"alice@example.com", "coins":0, "wins":0, "losses":0 } }
```
Validation: `email` unique, `password` min 8.

#### `POST /api/login`
```bash
curl -X POST http://127.0.0.1:8000/api/login \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"alice@example.com","password":"password123"}'
```
Returns `200` with the same `{ token, user }` shape. Returns `422` on bad
credentials.

### Auth-protected (`auth:sanctum`, send `Authorization: Bearer <token>`)

#### `GET /api/me`
Used by the Node game service to identify which user owns a websocket
connection. Response shape is contractual:
```bash
curl http://127.0.0.1:8000/api/me \
  -H 'Accept: application/json' -H 'Authorization: Bearer <token>'
```
```json
{ "user": { "id":1, "name":"Alice", "email":"alice@example.com", "coins":0, "wins":0, "losses":0 } }
```

#### `POST /api/logout`
Revokes the current access token. Returns `204`.
```bash
curl -X POST http://127.0.0.1:8000/api/logout \
  -H 'Accept: application/json' -H 'Authorization: Bearer <token>'
```

#### `POST /api/matches`
Creates a room (v1: generates ids, persists a `lobby` row; the room lifecycle
mostly lives in the Node service).
```bash
curl -X POST http://127.0.0.1:8000/api/matches \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <token>' \
  -d '{"settings":{"max_players":4}}'
```
Returns `201`:
```json
{ "match": { "id": "<uuid>", "code": "AB12C" } }
```
`code` is a 5-char uppercase shareable room code.

### Internal (the coins core)

#### `POST /internal/award-win`
**Not** behind Sanctum. Guarded by the `X-Internal-Secret` header, which must
equal `config('services.internal.secret')`. Wrong/missing header → `403`.

```bash
curl -X POST http://127.0.0.1:8000/internal/award-win \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H 'X-Internal-Secret: dev-kadi-secret-change-me' \
  -d '{
        "match_id": "8f1c...-uuid-from-node",
        "winner_user_id": 1,
        "participants": [{"user_id": 1}, {"user_id": 2}],
        "settings": {"deck": "standard"}
      }'
```

**Contract** (all in a single DB transaction, **idempotent on `match_id`**):

- `participants` are the **human** players only (the Node service omits AI).
- If a `matches` row with that `id` is already `finished`, nothing is awarded
  again and the response is:
  ```json
  { "match_id": "<id>", "already_awarded": true }
  ```
- Otherwise: upserts the match row (`status='finished'`, `winner_user_id`,
  `settings`, `finished_at=now`), creates `match_players` rows (winner
  `result='won'`, others `'lost'`), inserts a `+100` `coin_transactions` row
  (`reason='win'`) for the winner, increments the winner's `coins` by 100 and
  `wins` by 1, and increments each other participant's `losses` by 1. Returns:
  ```json
  { "match_id": "<id>", "winner": { "user_id": 1, "coins": 100 } }
  ```

Idempotency/race-safety: the match row is `SELECT ... FOR UPDATE`-locked inside
the transaction and the status is re-checked. A unique constraint on
`coin_transactions (match_id, reason)` is a hard backstop against double-award.

## Data model

- **users** — standard Laravel users plus `coins` (unsigned big int, default 0), `wins` (unsigned int, default 0), `losses` (unsigned int, default 0).
- **matches** — string/uuid PK (generated by the Node service), `status` enum (`lobby`/`playing`/`finished`), nullable `winner_user_id`, json `settings`, `started_at`/`finished_at`.
- **match_players** — `match_id`, `user_id`, `seat_index`, `is_ai`, `result` enum (`won`/`lost`); unique on `(match_id, user_id)`.
- **coin_transactions** — append-only ledger and source of truth: `user_id`, nullable `match_id`, `amount` (e.g. `+100`), `reason`; unique on `(match_id, reason)`.
