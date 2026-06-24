#!/usr/bin/env bash
#
# End-to-end smoke test for the Kadi API.
#
# Boots `php artisan serve` on a free port, then:
#   1. registers a user
#   2. logs in
#   3. hits /api/me with the bearer token
#   4. calls /internal/award-win TWICE for the same match_id and asserts the
#      winner's coin balance only increased by 100 once (idempotency).
#
# Requires: php, curl, python3 (for JSON parsing). Run from the repo root.
#
set -euo pipefail

cd "$(dirname "$0")"

PORT="${PORT:-8123}"
BASE="http://127.0.0.1:${PORT}"
SECRET="$(php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config("services.internal.secret");')"

# Fresh DB so the run is deterministic.
php artisan migrate:fresh --force >/dev/null

echo "Starting server on :${PORT} ..."
php artisan serve --port="${PORT}" >/dev/null 2>&1 &
SERVER_PID=$!
trap 'kill "${SERVER_PID}" 2>/dev/null || true' EXIT

# Wait for the server to come up.
for _ in $(seq 1 30); do
  if curl -fsS "${BASE}/up" >/dev/null 2>&1; then break; fi
  sleep 0.3
done

json() { python3 -c 'import sys,json; print(json.load(sys.stdin)'"$1"')'; }

EMAIL="smoke+$(date +%s)@example.com"

echo "1) Register ..."
REG=$(curl -fsS -X POST "${BASE}/api/register" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"name\":\"Smoke\",\"email\":\"${EMAIL}\",\"password\":\"password123\"}")
USER_ID=$(echo "${REG}" | json "['user']['id']")
echo "   user_id=${USER_ID}"

echo "2) Login ..."
LOGIN=$(curl -fsS -X POST "${BASE}/api/login" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"email\":\"${EMAIL}\",\"password\":\"password123\"}")
TOKEN=$(echo "${LOGIN}" | json "['token']")
echo "   token=${TOKEN:0:12}..."

echo "3) GET /api/me ..."
ME=$(curl -fsS "${BASE}/api/me" -H 'Accept: application/json' -H "Authorization: Bearer ${TOKEN}")
echo "   me: ${ME}"
ME_ID=$(echo "${ME}" | json "['user']['id']")
[ "${ME_ID}" = "${USER_ID}" ] || { echo "FAIL: /api/me returned wrong user"; exit 1; }
START_COINS=$(echo "${ME}" | json "['user']['coins']")

# Create a loser so award-win has a non-winner participant.
LOSER=$(curl -fsS -X POST "${BASE}/api/register" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"name\":\"Loser\",\"email\":\"loser+$(date +%s)@example.com\",\"password\":\"password123\"}")
LOSER_ID=$(echo "${LOSER}" | json "['user']['id']")

MATCH_ID="smoke-match-$(date +%s)"
PAYLOAD="{\"match_id\":\"${MATCH_ID}\",\"winner_user_id\":${USER_ID},\"participants\":[{\"user_id\":${USER_ID}},{\"user_id\":${LOSER_ID}}]}"

echo "4a) award-win (first call) ..."
AW1=$(curl -fsS -X POST "${BASE}/internal/award-win" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "X-Internal-Secret: ${SECRET}" -d "${PAYLOAD}")
echo "    ${AW1}"
COINS_AFTER_1=$(echo "${AW1}" | json "['winner']['coins']")

echo "4b) award-win (second call, same match_id) ..."
AW2=$(curl -fsS -X POST "${BASE}/internal/award-win" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "X-Internal-Secret: ${SECRET}" -d "${PAYLOAD}")
echo "    ${AW2}"
ALREADY=$(echo "${AW2}" | json "['already_awarded']")

# Re-read the balance via /api/me.
ME2=$(curl -fsS "${BASE}/api/me" -H 'Accept: application/json' -H "Authorization: Bearer ${TOKEN}")
FINAL_COINS=$(echo "${ME2}" | json "['user']['coins']")

echo
echo "start coins:           ${START_COINS}"
echo "coins after 1st award: ${COINS_AFTER_1}"
echo "final coins:           ${FINAL_COINS}"
echo "2nd call already_awarded: ${ALREADY}"

EXPECTED=$((START_COINS + 100))
if [ "${FINAL_COINS}" -eq "${EXPECTED}" ] && [ "${ALREADY}" = "True" ]; then
  echo
  echo "PASS: coins increased by exactly 100 once; second award was idempotent."
  exit 0
else
  echo
  echo "FAIL: expected ${EXPECTED} coins and already_awarded=True."
  exit 1
fi
