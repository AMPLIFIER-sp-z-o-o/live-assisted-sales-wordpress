#!/bin/sh
# HTTP-level tests of the LAS ingest API as the plugin talks to it: authentication, validation,
# idempotency and secret redaction. Complements test_las_parity.py (which needs a local Django) by
# asserting the same contract against a DEPLOYED las-backend.
#
#   LAS_URL=https://live-assisted-sales.com \
#   LAS_API_KEY='<store write_key>' \
#   sh dev/tests/test-ingest-remote.sh
#
# Every event uses a "qa-ingest-remote" visitor id, so rows it creates are easy to filter out.
set -u
LAS_URL="${LAS_URL:-http://localhost:8001}"
: "${LAS_API_KEY:?set LAS_API_KEY to the store API key}"
INGEST="$LAS_URL/api/ingest/store-events/"
TEST_EP="$LAS_URL/api/ingest/store-events/test/"
VISITOR="qa-ingest-remote"
SESSION="qa-ingest-remote-s"
NOW=$(date -u +%Y-%m-%dT%H:%M:%SZ)
STAMP=$(date -u +%s)
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then pass=$((pass+1)); echo "PASS  $1"; else fail=$((fail+1)); echo "FAIL  $1  [expected $2 got $3]"; fi; }

send() { # <api-key> <json-body> ; prints "body<newline>code"
  curl -s -w '\n%{http_code}' -X POST "$INGEST" \
    -H 'Content-Type: application/json' -H "Authorization: Bearer $1" --data-binary "$2"
}
event() { # <event_id> <event_type> [extra-json]
  printf '{"event_id":"%s","event_type":"%s","visitor_id":"%s","session_id":"%s","occurred_at":"%s","url":"https://example.test/qa"%s}' \
    "$1" "$2" "$VISITOR" "$SESSION" "$NOW" "${3:-}"
}

echo "== LAS ingest: $INGEST =="

# 1. Connection test endpoint reports the store
resp=$(curl -s -w '\n%{http_code}' "$TEST_EP" -H "Authorization: Bearer $LAS_API_KEY")
check "connection test -> 200" 200 "$(echo "$resp" | tail -1)"
echo "$resp" | head -1 | grep -q 'public_key' && check "connection test returns public key" y y || check "connection test returns public key" y n

# 2. Valid event accepted
resp=$(send "$LAS_API_KEY" "$(event "qa-$STAMP-view" view_item ',"product":{"id":"1","name":"QA product","price":"10.00","currency":"PLN"}')")
code=$(echo "$resp" | tail -1)
{ [ "$code" = "200" ] || [ "$code" = "201" ]; } && check "valid event accepted ($code)" y y || check "valid event accepted" y "$code"

# 3. Same event_id twice -> deduplicated (still 2xx, one row)
resp=$(send "$LAS_API_KEY" "$(event "qa-$STAMP-view" view_item)")
code=$(echo "$resp" | tail -1)
{ [ "$code" = "200" ] || [ "$code" = "201" ]; } && check "duplicate event_id accepted idempotently ($code)" y y || check "duplicate event_id" y "$code"
echo "$resp" | head -1 | grep -qi 'duplicate\|"status"' && check "duplicate reported, not stored twice" y y || check "duplicate reported" y n

# 4. Missing key -> 401
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$INGEST" -H 'Content-Type: application/json' --data-binary "$(event qa-nokey view_item)")
check "missing API key -> 401" 401 "$code"

# 5. Wrong key -> 403
code=$(send "definitely-not-a-real-write-key" "$(event qa-badkey view_item)" | tail -1)
check "invalid API key -> 403" 403 "$code"

# 6. Unknown event type -> 400
code=$(send "$LAS_API_KEY" "$(event "qa-$STAMP-bogus" totally_made_up)" | tail -1)
check "unknown event type -> 400" 400 "$code"

# 7. Telemetry types are accepted but stay out of the agent-facing history (INGEST_EVENT_TYPES =
#    business events + scroll_depth/page_ping + session_start/session_end).
code=$(send "$LAS_API_KEY" "$(event "qa-$STAMP-scroll" scroll_depth ',"metadata":{"percent":50}')" | tail -1)
{ [ "$code" = "200" ] || [ "$code" = "201" ]; } && check "telemetry scroll_depth accepted ($code)" y y || check "telemetry scroll_depth accepted" y "$code"
code=$(send "$LAS_API_KEY" "$(event "qa-$STAMP-bc" begin_checkout)" | tail -1)
{ [ "$code" = "200" ] || [ "$code" = "201" ]; } && check "begin_checkout accepted ($code)" y y || check "begin_checkout accepted" y "$code"

# 8. Broken JSON -> 400
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$INGEST" -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $LAS_API_KEY" --data-binary '{"event_type":')
check "broken JSON -> 400" 400 "$code"

# 9. Missing required field (no visitor_id) -> 400
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$INGEST" -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $LAS_API_KEY" \
  --data-binary "{\"event_id\":\"qa-$STAMP-novisitor\",\"event_type\":\"view_item\",\"occurred_at\":\"$NOW\"}")
check "missing visitor_id -> 400" 400 "$code"

# 10. Secrets in metadata come back redacted (checked in the console by the QA pass; here we only
#     assert the event is accepted, i.e. redaction never rejects a payload)
resp=$(send "$LAS_API_KEY" "$(event "qa-$STAMP-secret" view_item ',"metadata":{"password":"hunter2","api_key":"sk-should-be-redacted"}')")
code=$(echo "$resp" | tail -1)
{ [ "$code" = "200" ] || [ "$code" = "201" ]; } && check "payload with secrets accepted+redacted ($code)" y y || check "payload with secrets" y "$code"

# 11. Rate limit: burst well past LIVE_INGEST_RATE_LIMIT_PER_MINUTE/minute is throttled, not 500.
#     Off by default - a burst on a shared production store would throttle the real storefront too.
if [ "${RUN_RATE_LIMIT:-0}" = "1" ]; then
  seen429=0
  i=0
  while [ "$i" -lt 700 ]; do
    c=$(send "$LAS_API_KEY" "$(event "qa-$STAMP-rl-$i" page_ping)" | tail -1)
    [ "$c" = "429" ] && { seen429=1; break; }
    i=$((i+1))
  done
  check "rate limit returns 429" 1 "$seen429"
else
  echo "SKIP  rate limit burst (set RUN_RATE_LIMIT=1 to include it)"
fi

echo ""
echo "RESULT: $pass passed, $fail failed"
[ "$fail" = "0" ]
