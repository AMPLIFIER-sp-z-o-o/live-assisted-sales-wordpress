#!/bin/sh
# HTTP-level edge tests of the browser-events proxy against ANY store (local or a public demo).
# Port of test-rest.sh: same 12 assertions, but the store URL and the same-origin value come from
# the environment, and the cross-origin negatives use foreign hosts instead of a port swap (a
# public store has no second port to abuse).
#
#   BASE_URL=https://las-wordpress-demo.ampliapps.com sh dev/tests/test-rest-remote.sh
#
# Safe to run against production: every event carries a "qa-rest-remote" visitor id, so the rows
# it creates are trivially filtered out of the console.
set -u
BASE_URL="${BASE_URL:-http://localhost:8003}"
ENDPOINT="$BASE_URL/wp-json/amper-las/v1/events"
ORIGIN="$BASE_URL"
VISITOR="qa-rest-remote"
pass=0; fail=0
check() { # label expected actual
  if [ "$2" = "$3" ]; then pass=$((pass+1)); echo "PASS  $1"; else fail=$((fail+1)); echo "FAIL  $1  [expected $2 got $3]"; fi
}
post() { # extra-curl-args... ; prints "body<newline>code"
  curl -s -w '\n%{http_code}' -X POST "$ENDPOINT" -H 'Content-Type: application/json' "$@"
}

echo "== Browser-event proxy: $ENDPOINT =="

# 1. Valid same-origin batch
resp=$(post -H "Origin: $ORIGIN" -d "{\"events\":[{\"event_type\":\"page_ping\",\"visitor_id\":\"$VISITOR\",\"session_id\":\"$VISITOR-s\",\"metadata\":{\"engaged_ms\":1000}}]}")
code=$(echo "$resp" | tail -1); body=$(echo "$resp" | head -1)
check "valid batch -> 200" 200 "$code"
echo "$body" | grep -q '"sent":1' && check "valid batch sent:1" y y || check "valid batch sent:1" y n

# 2. Cross-origin (Origin header) -> 403
code=$(post -H 'Origin: https://evil.example' -d '{"events":[]}' | tail -1)
check "cross-origin -> 403" 403 "$code"

# 3. Cross-origin via Referer only -> 403
code=$(post -H 'Referer: https://evil.example/page' -d '{"events":[]}' | tail -1)
check "cross-origin referer -> 403" 403 "$code"

# 4. Look-alike host (same suffix, different host) -> 403
code=$(post -H "Origin: https://evil-$(echo "$BASE_URL" | sed 's~^https\?://~~')" -d '{"events":[]}' | tail -1)
check "look-alike host -> 403" 403 "$code"

# 5. Invalid JSON -> 400
code=$(post -H "Origin: $ORIGIN" -d '{broken json' | tail -1)
check "invalid JSON -> 400" 400 "$code"

# 6. Oversized payload (>32 KB) -> 413
big=$(python3 -c "import json;print(json.dumps({'events':[{'event_type':'page_ping','metadata':{'junk':'x'*40000}}]}))")
code=$(printf '%s' "$big" | curl -s -o /dev/null -w '%{http_code}' -X POST "$ENDPOINT" -H 'Content-Type: application/json' -H "Origin: $ORIGIN" --data-binary @-)
check "oversize -> 413" 413 "$code"

# 7. Unsupported event type -> 400 + message
resp=$(post -H "Origin: $ORIGIN" -d '{"events":[{"event_type":"totally_made_up"}]}')
check "unsupported type -> 400" 400 "$(echo "$resp" | tail -1)"
echo "$resp" | head -1 | grep -qi 'unsupported event type' && check "unsupported type message" y y || check "unsupported type message" y n

# 8. Batch of 30 -> capped at 25
batch=$(python3 -c "
import json
print(json.dumps({'events':[{'event_type':'page_ping','visitor_id':'$VISITOR','metadata':{'i':i}} for i in range(30)]}))")
sent=$(printf '%s' "$batch" | curl -s -X POST "$ENDPOINT" -H 'Content-Type: application/json' -H "Origin: $ORIGIN" --data-binary @- | python3 -c "import json,sys;print(json.load(sys.stdin).get('sent'))")
check "batch of 30 -> sent 25" 25 "$sent"

# 9. Bare array payload accepted
post -H "Origin: $ORIGIN" -d "[{\"event_type\":\"page_ping\",\"visitor_id\":\"$VISITOR\"}]" | head -1 | grep -q '"sent":1' \
  && check "bare array accepted" y y || check "bare array accepted" y n

# 10. Single object payload accepted
post -H "Origin: $ORIGIN" -d "{\"event_type\":\"page_ping\",\"visitor_id\":\"$VISITOR\"}" | head -1 | grep -q '"sent":1' \
  && check "single object accepted" y y || check "single object accepted" y n

# 11. No Origin/Referer at all (sendBeacon) -> allowed
code=$(post -d "{\"events\":[{\"event_type\":\"page_ping\",\"visitor_id\":\"$VISITOR\"}]}" | tail -1)
check "no origin header allowed" 200 "$code"

# 12. GET is not a route
code=$(curl -s -o /dev/null -w '%{http_code}' "$ENDPOINT" -H "Origin: $ORIGIN")
{ [ "$code" = "404" ] || [ "$code" = "405" ]; } && check "GET rejected ($code)" y y || check "GET rejected" y "$code"

echo ""
echo "RESULT: $pass passed, $fail failed"
[ "$fail" = "0" ]
