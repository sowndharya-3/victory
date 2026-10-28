#!/usr/bin/env bash
# SP-5 order/checkout HTTP tests. Sections are appended by later tasks.
set -uo pipefail

BASE_URL="${BASE_URL:-http://localhost:8080}"
PASS=0
FAIL=0

check() {
  if [ "$2" = "true" ]; then
    echo "PASS: $1"
    PASS=$((PASS+1))
  else
    echo "FAIL: $1"
    FAIL=$((FAIL+1))
  fi
}

sql() { docker compose exec -T mysql mysql -u vtee -pchangeme vtee -N -e "$1" 2>/dev/null; }
json_has() { echo "$1" | grep -Eq "$2" && echo true || echo false; }

TMP="${TMPDIR:-/tmp}"
BOOK_ID=""

cleanup() {
  # Always restore settings and remove fixtures, even on failure.
  sql "UPDATE settings SET \`value\`='0' WHERE \`key\`='gst_percent_digital'; UPDATE settings SET \`value\`='inclusive' WHERE \`key\`='gst_mode';
       DELETE FROM referral_partners WHERE referral_code IN ('VTP-TEST01','VTP-TEST02');
       DELETE FROM rate_limits WHERE bucket LIKE 'referral-validate:%';"
  if [ -n "$BOOK_ID" ]; then
    sql "DELETE FROM book_preview_pages WHERE book_id=$BOOK_ID; DELETE FROM books WHERE id=$BOOK_ID;"
  fi
}
trap cleanup EXIT

echo "=== Setup ==="
ADMIN_TOKEN=$(curl -s -X POST "$BASE_URL/api/admin/login" -H "Content-Type: application/json" -d '{"username":"admin","password":"adminpass123"}' | grep -o '"token":"[^"]*"' | cut -d'"' -f4)
check "admin token obtained" "$([ -n "$ADMIN_TOKEN" ] && echo true || echo false)"

CREATE=$(curl -s -X POST "$BASE_URL/api/admin/books" -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d '{"title":"Order Test Book","onlinePrice":199,"hardcopyPrice":349}')
BOOK_ID=$(echo "$CREATE" | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')
check "test book created" "$([ -n "$BOOK_ID" ] && echo true || echo false)"
sql "UPDATE settings SET \`value\`='0' WHERE \`key\`='gst_percent_digital'; UPDATE settings SET \`value\`='inclusive' WHERE \`key\`='gst_mode';"

echo "=== 1. Checkout quote ==="
check "unpublished book quote -> 404" "$([ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/api/checkout/quote?bookId=$BOOK_ID&type=digital")" = "404" ] && echo true || echo false)"
curl -s -X POST "$BASE_URL/api/admin/books/$BOOK_ID/publish" -H "Authorization: Bearer $ADMIN_TOKEN" >/dev/null

CODE=$(curl -s -o "$TMP/quote.json" -w '%{http_code}' "$BASE_URL/api/checkout/quote?bookId=$BOOK_ID&type=digital")
Q=$(cat "$TMP/quote.json")
check "digital quote -> 200" "$([ "$CODE" = "200" ] && echo true || echo false)"
check "digital quote subtotal 199, gst 0, total 199" "$(json_has "$Q" '"subtotal":199(\.0)?,.*"gst":0(\.0)?,.*"gstPercent":0(\.0)?,.*"gstMode":"inclusive",.*"total":199(\.0)?[,}]')"
check "digital quote has bookId, purchaseType, INR" "$(json_has "$Q" "\"bookId\":$BOOK_ID,\"purchaseType\":\"digital\",\"currency\":\"INR\"")"
Q=$(curl -s "$BASE_URL/api/checkout/quote?bookId=$BOOK_ID&type=hardcopy")
check "hardcopy quote subtotal 349, total 349" "$(json_has "$Q" '"subtotal":349(\.0)?,.*"total":349(\.0)?[,}]')"

sql "UPDATE settings SET \`value\`='18' WHERE \`key\`='gst_percent_digital'; UPDATE settings SET \`value\`='exclusive' WHERE \`key\`='gst_mode';"
Q=$(curl -s "$BASE_URL/api/checkout/quote?bookId=$BOOK_ID&type=digital")
check "exclusive 18% quote: gst 35.82, total 234.82" "$(json_has "$Q" '"gst":35\.82,"gstPercent":18(\.0)?,"gstMode":"exclusive","total":234\.82')"
sql "UPDATE settings SET \`value\`='0' WHERE \`key\`='gst_percent_digital'; UPDATE settings SET \`value\`='inclusive' WHERE \`key\`='gst_mode';"

check "bad type -> 400" "$([ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/api/checkout/quote?bookId=$BOOK_ID&type=bogus")" = "400" ] && echo true || echo false)"
check "bad bookId -> 400" "$([ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/api/checkout/quote?bookId=abc&type=digital")" = "400" ] && echo true || echo false)"
check "missing book -> 404" "$([ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/api/checkout/quote?bookId=99999999&type=digital")" = "404" ] && echo true || echo false)"
curl -s -X POST "$BASE_URL/api/admin/books/$BOOK_ID/unpublish" -H "Authorization: Bearer $ADMIN_TOKEN" >/dev/null
check "unpublished again -> 404" "$([ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/api/checkout/quote?bookId=$BOOK_ID&type=digital")" = "404" ] && echo true || echo false)"

echo "=== 2. Referral validate ==="
sql "DELETE FROM rate_limits WHERE bucket LIKE 'referral-validate:%';
     DELETE FROM referral_partners WHERE referral_code IN ('VTP-TEST01','VTP-TEST02');
     INSERT INTO referral_partners (name, phone, whatsapp, gmail, referral_code, active) VALUES
       ('Meena Test','9876543210','9876543210','meena-test@example.com','VTP-TEST01',1),
       ('Inactive Test','9876543211','9876543211','inactive-test@example.com','VTP-TEST02',0);"

R=$(curl -s "$BASE_URL/api/referral/validate/VTP-TEST01")
check "active code -> valid true with masked name" "$(json_has "$R" '^\{"valid":true,"partnerName":"M\*\*\*"\}$')"
check "active code does not leak full name" "$(echo "$R" | grep -q 'Meena' && echo false || echo true)"
R=$(curl -s "$BASE_URL/api/referral/validate/vtp-test01")
check "lowercase code normalised -> valid true" "$(json_has "$R" '"valid":true')"
R=$(curl -s "$BASE_URL/api/referral/validate/VTP-NOPE99")
check "unknown code -> valid false" "$(json_has "$R" '^\{"valid":false\}$')"
R=$(curl -s "$BASE_URL/api/referral/validate/VTP-TEST02")
check "inactive code -> valid false" "$(json_has "$R" '^\{"valid":false\}$')"

# Avoid straddling a minute boundary mid-burst (window is a fixed 60s bucket).
SEC=$((10#$(date +%S)))
[ "$SEC" -gt 25 ] && sleep $((61 - SEC))
sql "DELETE FROM rate_limits WHERE bucket LIKE 'referral-validate:%';"
LAST=""
for i in $(seq 1 31); do
  LAST=$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/api/referral/validate/VTP-TEST01")
  [ "$i" = "30" ] && THIRTIETH="$LAST"
done
check "30th call within a minute still 200" "$([ "$THIRTIETH" = "200" ] && echo true || echo false)"
check "31st call within a minute -> 429" "$([ "$LAST" = "429" ] && echo true || echo false)"
BODY=$(curl -s "$BASE_URL/api/referral/validate/VTP-TEST01")
check "429 body has rate_limited code" "$(json_has "$BODY" '"code":"rate_limited"')"

echo ""
echo "Results: $PASS passed, $FAIL failed"
[ "$FAIL" -gt 0 ] && exit 1
exit 0
