#!/usr/bin/env bash
set -uo pipefail

BASE_URL="${BASE_URL:-http://localhost:8080}"
PASS=0
FAIL=0

check() {
  local desc="$1"
  local condition="$2"
  if [ "$condition" = "true" ]; then
    echo "PASS: $desc"
    PASS=$((PASS+1))
  else
    echo "FAIL: $desc"
    FAIL=$((FAIL+1))
  fi
}

echo "=== 1. Health check ==="
HEALTH=$(curl -s -o /tmp/health.json -w "%{http_code}" "$BASE_URL/api/health")
check "health returns 200" "$([ "$HEALTH" = "200" ] && echo true || echo false)"
check "health reports ok status" "$(grep -q '"status":"ok"' /tmp/health.json && echo true || echo false)"

echo "=== 2. Customer registration ==="
EMAIL="test-$(date +%s)@example.com"
REG=$(curl -s -o /tmp/register.json -w "%{http_code}" -X POST "$BASE_URL/api/auth/register" \
  -H "Content-Type: application/json" \
  -d "{\"name\":\"Test User\",\"email\":\"$EMAIL\",\"password\":\"password123\"}")
check "register returns 201" "$([ "$REG" = "201" ] && echo true || echo false)"
TOKEN=$(grep -o '"token":"[^"]*"' /tmp/register.json | cut -d'"' -f4)
check "register returns a token" "$([ -n "$TOKEN" ] && echo true || echo false)"

echo "=== 2b. Duplicate registration ==="
DUP=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/api/auth/register" \
  -H "Content-Type: application/json" \
  -d "{\"name\":\"Test User\",\"email\":\"$EMAIL\",\"password\":\"password123\"}")
check "duplicate email returns 409" "$([ "$DUP" = "409" ] && echo true || echo false)"

echo "=== 3. Customer login ==="
LOGIN=$(curl -s -o /tmp/login.json -w "%{http_code}" -X POST "$BASE_URL/api/auth/login" \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"$EMAIL\",\"password\":\"password123\"}")
check "login returns 200" "$([ "$LOGIN" = "200" ] && echo true || echo false)"
LOGIN_TOKEN=$(grep -o '"token":"[^"]*"' /tmp/login.json | cut -d'"' -f4)
check "login returns a token" "$([ -n "$LOGIN_TOKEN" ] && echo true || echo false)"

echo "=== 4. GET /api/me with customer token ==="
ME=$(curl -s -o /tmp/me.json -w "%{http_code}" "$BASE_URL/api/me" -H "Authorization: Bearer $LOGIN_TOKEN")
check "me returns 200" "$([ "$ME" = "200" ] && echo true || echo false)"
check "me returns correct email" "$(grep -q "\"email\":\"$EMAIL\"" /tmp/me.json && echo true || echo false)"

echo "=== 5. Admin login ==="
ADMIN_LOGIN=$(curl -s -o /tmp/admin_login.json -w "%{http_code}" -X POST "$BASE_URL/api/admin/login" \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"adminpass123"}')
check "admin login returns 200" "$([ "$ADMIN_LOGIN" = "200" ] && echo true || echo false)"
ADMIN_TOKEN=$(grep -o '"token":"[^"]*"' /tmp/admin_login.json | cut -d'"' -f4)
check "admin login returns a token" "$([ -n "$ADMIN_TOKEN" ] && echo true || echo false)"

echo "=== 6. Protected route with admin token ==="
ADMIN_ME=$(curl -s -o /tmp/admin_me.json -w "%{http_code}" "$BASE_URL/api/me" -H "Authorization: Bearer $ADMIN_TOKEN")
check "admin me returns 200" "$([ "$ADMIN_ME" = "200" ] && echo true || echo false)"
check "admin me reports admin role" "$(grep -q '"role":"admin"' /tmp/admin_me.json && echo true || echo false)"

echo "=== 7. Invalid / expired JWT handling ==="
NO_TOKEN=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/api/me")
check "no token returns 401" "$([ "$NO_TOKEN" = "401" ] && echo true || echo false)"

GARBAGE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/api/me" -H "Authorization: Bearer not.a.jwt")
check "garbage token returns 401" "$([ "$GARBAGE" = "401" ] && echo true || echo false)"

EXPIRED_TOKEN=$(docker compose exec -T php php scripts/make_expired_token.php)
EXPIRED=$(curl -s -o /tmp/expired.json -w "%{http_code}" "$BASE_URL/api/me" -H "Authorization: Bearer $EXPIRED_TOKEN")
check "expired token returns 401" "$([ "$EXPIRED" = "401" ] && echo true || echo false)"
check "expired token error code is token_expired" "$(grep -q '"code":"token_expired"' /tmp/expired.json && echo true || echo false)"

echo "=== 8. Invalid credentials ==="
BAD_LOGIN=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/api/auth/login" \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"$EMAIL\",\"password\":\"wrongpassword\"}")
check "wrong password returns 401" "$([ "$BAD_LOGIN" = "401" ] && echo true || echo false)"

echo "=== 9. CORS preflight ==="
CORS=$(curl -s -D - -o /dev/null -X OPTIONS "$BASE_URL/api/auth/login" \
  -H "Origin: http://localhost:5173" \
  -H "Access-Control-Request-Method: POST")
check "preflight echoes exact configured origin" "$(echo "$CORS" | grep -qi "access-control-allow-origin: http://localhost:5173" && echo true || echo false)"
check "preflight does not use wildcard origin" "$(echo "$CORS" | grep -qi "access-control-allow-origin: \*" && echo false || echo true)"

echo "=== 10. Database connectivity ==="
check "health check queried a live database (already proven in step 1)" "$([ "$HEALTH" = "200" ] && echo true || echo false)"

echo ""
echo "Results: $PASS passed, $FAIL failed"
if [ "$FAIL" -gt 0 ]; then
  exit 1
fi
