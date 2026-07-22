#!/bin/sh
# HTTP-level edge tests of the browser-events proxy (run from the host with curl).
set -u
BASE="http://localhost:8003/wp-json/amper-las/v1/events"
pass=0; fail=0
check() { # label expected actual
  if [ "$2" = "$3" ]; then pass=$((pass+1)); echo "PASS  $1"; else fail=$((fail+1)); echo "FAIL  $1  [expected $2 got $3]"; fi
}

# 1. Valid same-origin batch
resp=$(curl -s -w '\n%{http_code}' -X POST "$BASE" -H 'Content-Type: application/json' -H 'Origin: http://localhost:8003' \
  -d '{"events":[{"event_type":"page_ping","visitor_id":"rest-test","session_id":"rest-test-s","metadata":{"engaged_ms":1000}}]}')
code=$(echo "$resp" | tail -1); body=$(echo "$resp" | head -1)
check "valid batch -> 200" 200 "$code"
echo "$body" | grep -q '"sent":1' && check "valid batch sent:1" y y || check "valid batch sent:1" y n

# 2. Cross-origin -> 403
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE" -H 'Content-Type: application/json' -H 'Origin: http://evil.example' -d '{"events":[]}')
check "cross-origin -> 403" 403 "$code"

# 3. Cross-origin via Referer only -> 403
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE" -H 'Content-Type: application/json' -H 'Referer: http://evil.example/page' -d '{"events":[]}')
check "cross-origin referer -> 403" 403 "$code"

# 4. Same host, wrong port -> 403
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE" -H 'Content-Type: application/json' -H 'Origin: http://localhost:9999' -d '{"events":[]}')
check "same host wrong port -> 403" 403 "$code"

# 5. Invalid JSON -> 400
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE" -H 'Content-Type: application/json' -H 'Origin: http://localhost:8003' -d '{broken json')
check "invalid JSON -> 400" 400 "$code"

# 6. Oversized payload -> 413
python - "$BASE" <<'EOF'
import sys, urllib.request, json
url = sys.argv[1]
big = json.dumps({"events":[{"event_type":"page_ping","metadata":{"junk":"x"*40000}}]}).encode()
req = urllib.request.Request(url, data=big, headers={"Content-Type":"application/json","Origin":"http://localhost:8003"})
try:
    urllib.request.urlopen(req)
    print("OVERSIZE_CODE=200")
except urllib.error.HTTPError as e:
    print("OVERSIZE_CODE=%s" % e.code)
EOF

# 7. Unsupported event type -> 400 + error message
resp=$(curl -s -w '\n%{http_code}' -X POST "$BASE" -H 'Content-Type: application/json' -H 'Origin: http://localhost:8003' \
  -d '{"events":[{"event_type":"totally_made_up"}]}')
code=$(echo "$resp" | tail -1)
check "unsupported type -> 400" 400 "$code"
echo "$resp" | head -1 | grep -q 'Unsupported event type' && check "unsupported type error msg" y y || check "unsupported type error msg" y n

# 8. Batch of 30 -> only 25 accepted
python - "$BASE" <<'EOF'
import sys, urllib.request, json
url = sys.argv[1]
events = [{"event_type":"page_ping","visitor_id":"rest-batch","metadata":{"i":i}} for i in range(30)]
req = urllib.request.Request(url, data=json.dumps({"events":events}).encode(), headers={"Content-Type":"application/json","Origin":"http://localhost:8003"})
body = urllib.request.urlopen(req).read().decode()
print("BATCH30=%s" % json.loads(body).get("sent"))
EOF

# 9. Bare list payload (b2c accepts a bare array too)
resp=$(curl -s -X POST "$BASE" -H 'Content-Type: application/json' -H 'Origin: http://localhost:8003' \
  -d '[{"event_type":"page_ping","visitor_id":"rest-list"}]')
echo "$resp" | grep -q '"sent":1' && check "bare array accepted" y y || check "bare array accepted" y n

# 10. Single object payload
resp=$(curl -s -X POST "$BASE" -H 'Content-Type: application/json' -H 'Origin: http://localhost:8003' \
  -d '{"event_type":"page_ping","visitor_id":"rest-single"}')
echo "$resp" | grep -q '"sent":1' && check "single object accepted" y y || check "single object accepted" y n

# 11. No Origin/Referer (beacon-like) -> allowed
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE" -H 'Content-Type: application/json' \
  -d '{"events":[{"event_type":"page_ping","visitor_id":"rest-noorigin"}]}')
check "no origin header allowed" 200 "$code"

# 12. GET method -> 404/405 (route is POST-only)
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE" -H 'Origin: http://localhost:8003')
[ "$code" = "404" ] || [ "$code" = "405" ] && check "GET rejected ($code)" y y || check "GET rejected" y "$code"

echo ""
echo "RESULT: $pass passed, $fail failed"
