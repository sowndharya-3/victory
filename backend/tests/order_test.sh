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

# Secrets come from backend/.env; never echo their values.
ENV_FILE="$(dirname "$0")/../.env"
RZP_SECRET=$(grep '^RAZORPAY_KEY_SECRET=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- | tr -d '\r')
WH_SECRET=$(grep '^RAZORPAY_WEBHOOK_SECRET=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- | tr -d '\r')
if [ -z "$RZP_SECRET" ] || [ -z "$WH_SECRET" ]; then
  echo "ERROR: RAZORPAY_KEY_SECRET and RAZORPAY_WEBHOOK_SECRET must both be set in backend/.env" >&2
  exit 2
fi

cleanup() {
  # Always restore settings and remove fixtures, even on failure.
  sql "UPDATE settings SET \`value\`='0' WHERE \`key\`='gst_percent_digital'; UPDATE settings SET \`value\`='inclusive' WHERE \`key\`='gst_mode';
       DELETE FROM rate_limits WHERE bucket LIKE 'referral-validate:%' OR bucket LIKE 'code-redeem-%' OR bucket LIKE 'code-reissue:%' OR bucket LIKE 'track:%';
       DELETE FROM payment_events WHERE event_id LIKE 'evt_ordertest_%';"
  if [ -n "$BOOK_ID" ]; then
    sql "DELETE FROM audit_logs WHERE entity_type='access_code' AND entity_id IN (SELECT id FROM access_codes WHERE book_id=$BOOK_ID);
         DELETE FROM audit_logs WHERE entity_type='refund' AND entity_id IN (SELECT id FROM refunds WHERE order_id IN (SELECT id FROM orders WHERE book_id=$BOOK_ID));
         DELETE FROM refunds WHERE order_id IN (SELECT id FROM orders WHERE book_id=$BOOK_ID);
         DELETE FROM access_codes WHERE book_id=$BOOK_ID;
         DELETE FROM commissions WHERE order_id IN (SELECT id FROM orders WHERE book_id=$BOOK_ID);
         DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE book_id=$BOOK_ID);
         DELETE FROM hardcopy_addresses WHERE order_id IN (SELECT id FROM orders WHERE book_id=$BOOK_ID);
         DELETE FROM book_access WHERE book_id=$BOOK_ID;
         DELETE FROM orders WHERE book_id=$BOOK_ID;
         DELETE FROM book_preview_pages WHERE book_id=$BOOK_ID; DELETE FROM books WHERE id=$BOOK_ID;"
  fi
  # Section 8 orders the real book 2: remove every row hanging off test users, whatever the book.
  TU="SELECT id FROM users WHERE email LIKE 'ordertest-%@example.com'"
  TO="SELECT id FROM orders WHERE user_id IN ($TU)"
  sql "DELETE FROM audit_logs WHERE user_id IN ($TU) OR (entity_type='refund' AND entity_id IN (SELECT id FROM refunds WHERE order_id IN ($TO)))
         OR (entity_type='access_code' AND entity_id IN (SELECT id FROM access_codes WHERE order_id IN ($TO)));
       DELETE FROM refunds WHERE order_id IN ($TO);
       DELETE FROM access_codes WHERE order_id IN ($TO);
       DELETE FROM commissions WHERE order_id IN ($TO);
       DELETE FROM payments WHERE order_id IN ($TO);
       DELETE FROM hardcopy_addresses WHERE order_id IN ($TO);
       DELETE FROM book_access WHERE user_id IN ($TU);
       DELETE FROM orders WHERE user_id IN ($TU);
       DELETE FROM rate_limits WHERE bucket LIKE 'reader-page:%' AND SUBSTRING_INDEX(bucket,':',-1) IN ($TU);"
  sql "DELETE FROM rate_limits WHERE bucket LIKE 'order-create:%' AND SUBSTRING_INDEX(bucket,':',-1) IN (SELECT id FROM users WHERE email LIKE 'ordertest-%@example.com');
       DELETE FROM users WHERE email LIKE 'ordertest-%@example.com';
       DELETE FROM referral_partners WHERE referral_code IN ('VTP-TEST01','VTP-TEST02');"
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

echo "=== 3. Create order ==="
register() { # $1 email -> prints "token id"
  local r; r=$(curl -s -X POST "$BASE_URL/api/auth/register" -H "Content-Type: application/json" -d "{\"name\":\"T\",\"email\":\"$1\",\"password\":\"password123\"}")
  echo "$(echo "$r" | grep -o '"token":"[^"]*"' | cut -d'"' -f4) $(echo "$r" | grep -o '"user":{"id":[0-9]*' | grep -o '[0-9]*$')"
}
post_order() { # $1 token $2 json -> body in $TMP/o.json, code in $OCODE
  OCODE=$(curl -s -o "$TMP/o.json" -w '%{http_code}' -X POST "$BASE_URL/api/orders" -H "Content-Type: application/json" ${1:+-H "Authorization: Bearer $1"} -d "$2")
  O=$(cat "$TMP/o.json")
}
STAMP="$(date +%s)$$"
EMAIL_A="ordertest-a-$STAMP@example.com"; EMAIL_B="ordertest-b-$STAMP@example.com"
read -r TOKEN_A UID_A <<<"$(register "$EMAIL_A")"
read -r TOKEN_B UID_B <<<"$(register "$EMAIL_B")"
check "order test customers registered" "$([ -n "$TOKEN_A" ] && [ -n "$UID_A" ] && [ -n "$TOKEN_B" ] && [ -n "$UID_B" ] && echo true || echo false)"
sql "DELETE FROM rate_limits WHERE bucket IN ('order-create:$UID_A','order-create:$UID_B');"

post_order "" "{\"bookId\":$BOOK_ID,\"purchaseType\":\"digital\"}"
check "no token -> 401" "$([ "$OCODE" = "401" ] && echo true || echo false)"
post_order "$TOKEN_A" "{\"bookId\":$BOOK_ID,\"purchaseType\":\"digital\"}"
check "unpublished book -> 404" "$([ "$OCODE" = "404" ] && echo true || echo false)"
curl -s -X POST "$BASE_URL/api/admin/books/$BOOK_ID/publish" -H "Authorization: Bearer $ADMIN_TOKEN" >/dev/null

post_order "$TOKEN_A" "{\"bookId\":$BOOK_ID,\"purchaseType\":\"bogus\"}"
check "bad purchaseType -> 400" "$([ "$OCODE" = "400" ] && echo true || echo false)"
post_order "$TOKEN_A" "{\"bookId\":\"x\",\"purchaseType\":\"digital\"}"
check "bad bookId -> 400" "$([ "$OCODE" = "400" ] && echo true || echo false)"

post_order "$TOKEN_A" "{\"bookId\":$BOOK_ID,\"purchaseType\":\"digital\",\"amount\":1}"
check "digital create -> 201" "$([ "$OCODE" = "201" ] && echo true || echo false)"
check "order total 199, pending, razorpay amount 19900 (body amount ignored)" "$(json_has "$O" '"status":"pending".*"total":199(\.0)?[,}].*"razorpay":\{"keyId":"[^"]*","orderId":"order_fake_[0-9a-f]+","amount":19900,"currency":"INR"\}')"
check "order number format VT-YYYYMMDD-XXXXXX" "$(json_has "$O" '"orderNumber":"VT-[0-9]{8}-[0-9A-HJKMNP-TV-Z]{6}"')"
check "digital order fulfilment not_applicable" "$(json_has "$O" '"fulfilmentStatus":"not_applicable"')"
ORDER_ID=$(echo "$O" | grep -o '"order":{"id":[0-9]*' | grep -o '[0-9]*$')
check "orders row amount 199.00 pending; payments row created with razorpay id" "$([ "$(sql "SELECT CONCAT(o.amount,'|',o.status,'|',p.status,'|',LEFT(p.razorpay_order_id,11),'|',p.amount) FROM orders o JOIN payments p ON p.order_id=o.id WHERE o.id=$ORDER_ID")" = "199.00|pending|created|order_fake_|199.00" ] && echo true || echo false)"

post_order "$TOKEN_A" "{\"bookId\":$BOOK_ID,\"purchaseType\":\"hardcopy\"}"
check "hardcopy without address -> 400 fields.address" "$([ "$OCODE" = "400" ] && json_has "$O" '"fields":\{"address' || echo false)"
post_order "$TOKEN_A" "{\"bookId\":$BOOK_ID,\"purchaseType\":\"hardcopy\",\"address\":{\"fullName\":\"A\",\"phone\":\"123\"}}"
check "hardcopy with invalid address -> 400 fields.address.*" "$([ "$OCODE" = "400" ] && json_has "$O" '"address\.phone"' || echo false)"
ADDR='{"fullName":"Asha Kumar","phone":"+91 98765-43210","addressLine":"12 Main Rd","city":"Madurai","district":"Madurai","state":"Tamil Nadu","pincode":"625001"}'
post_order "$TOKEN_A" "{\"bookId\":$BOOK_ID,\"purchaseType\":\"hardcopy\",\"address\":$ADDR}"
check "hardcopy with valid address -> 201" "$([ "$OCODE" = "201" ] && echo true || echo false)"
check "hardcopy response has camelCase address, total 349" "$(json_has "$O" '"total":349(\.0)?[,}].*"address":\{.*"fullName":"Asha Kumar".*"phone":"9876543210".*"pincode":"625001"')"
HC_ID=$(echo "$O" | grep -o '"order":{"id":[0-9]*' | grep -o '[0-9]*$')
check "hardcopy_addresses row exists, fulfilment not_applicable" "$([ "$(sql "SELECT CONCAT(a.pincode,'|',a.phone,'|',o.fulfilment_status) FROM hardcopy_addresses a JOIN orders o ON o.id=a.order_id WHERE a.order_id=$HC_ID")" = "625001|9876543210|not_applicable" ] && echo true || echo false)"

# Referral: active partner, self-referral, unknown code
sql "DELETE FROM referral_partners WHERE referral_code IN ('VTP-TEST01','VTP-TEST02');
     INSERT INTO referral_partners (name, phone, whatsapp, gmail, referral_code, active) VALUES
       ('Meena Test','9876543210','9876543210','meena-test@example.com','VTP-TEST01',1);"
post_order "$TOKEN_A" "{\"bookId\":$BOOK_ID,\"purchaseType\":\"digital\",\"referralCode\":\"vtp-test01\"}"
RID=$(echo "$O" | grep -o '"order":{"id":[0-9]*' | grep -o '[0-9]*$')
check "active referral code stored in referral_partner_id + snapshot" "$([ "$OCODE" = "201" ] && [ "$(sql "SELECT (referral_partner_id=(SELECT id FROM referral_partners WHERE referral_code='VTP-TEST01')) AND referral_code_snapshot='VTP-TEST01' FROM orders WHERE id=$RID")" = "1" ] && echo true || echo false)"
sql "UPDATE referral_partners SET gmail='$EMAIL_A' WHERE referral_code='VTP-TEST01';"
post_order "$TOKEN_A" "{\"bookId\":$BOOK_ID,\"purchaseType\":\"digital\",\"referralCode\":\"VTP-TEST01\"}"
RID=$(echo "$O" | grep -o '"order":{"id":[0-9]*' | grep -o '[0-9]*$')
check "self-referral -> 201, referral_partner_id NULL" "$([ "$OCODE" = "201" ] && [ "$(sql "SELECT referral_partner_id IS NULL AND referral_code_snapshot IS NULL FROM orders WHERE id=$RID")" = "1" ] && echo true || echo false)"
post_order "$TOKEN_A" "{\"bookId\":$BOOK_ID,\"purchaseType\":\"digital\",\"referralCode\":\"VTP-NOPE99\"}"
RID=$(echo "$O" | grep -o '"order":{"id":[0-9]*' | grep -o '[0-9]*$')
check "unknown referral code -> 201, referral_partner_id NULL" "$([ "$OCODE" = "201" ] && [ "$(sql "SELECT referral_partner_id IS NULL FROM orders WHERE id=$RID")" = "1" ] && echo true || echo false)"

# Already owned (digital only)
sql "INSERT INTO book_access (user_id, book_id) VALUES ($UID_A, $BOOK_ID);"
post_order "$TOKEN_A" "{\"bookId\":$BOOK_ID,\"purchaseType\":\"digital\"}"
check "digital with active book_access -> 409 already_owned" "$([ "$OCODE" = "409" ] && json_has "$O" '"code":"already_owned"' || echo false)"
post_order "$TOKEN_A" "{\"bookId\":$BOOK_ID,\"purchaseType\":\"hardcopy\",\"address\":$ADDR}"
check "hardcopy with book_access still allowed -> 201" "$([ "$OCODE" = "201" ] && echo true || echo false)"
sql "UPDATE book_access SET revoked_at=NOW() WHERE user_id=$UID_A AND book_id=$BOOK_ID;"
post_order "$TOKEN_A" "{\"bookId\":$BOOK_ID,\"purchaseType\":\"digital\"}"
check "digital with revoked book_access -> 201" "$([ "$OCODE" = "201" ] && echo true || echo false)"
sql "DELETE FROM book_access WHERE user_id=$UID_A AND book_id=$BOOK_ID; UPDATE orders SET status='paid' WHERE id=$ORDER_ID;"
post_order "$TOKEN_A" "{\"bookId\":$BOOK_ID,\"purchaseType\":\"digital\"}"
check "digital with paid digital order (unredeemed) -> 409 already_owned" "$([ "$OCODE" = "409" ] && json_has "$O" '"code":"already_owned"' || echo false)"

# Rate limit: 20/hour per user (fixed hour window; avoid straddling the boundary)
MIN=$((10#$(date +%M)))
[ "$MIN" -ge 58 ] && sleep $(( (60 - MIN) * 60 + 2 ))
sql "DELETE FROM rate_limits WHERE bucket='order-create:$UID_B';"
CODES=""
for i in $(seq 1 21); do
  post_order "$TOKEN_B" "{\"bookId\":$BOOK_ID,\"purchaseType\":\"digital\"}"
  CODES="$CODES $OCODE"
done
check "20 creates in an hour -> 201 each" "$([ "$(echo $CODES | cut -d' ' -f1-20 | tr ' ' '\n' | sort -u)" = "201" ] && echo true || echo false)"
check "21st create in an hour -> 429" "$([ "$OCODE" = "429" ] && echo true || echo false)"

echo "=== 4. Verify payment and customer order APIs ==="
sign() { printf '%s|%s' "$1" "$2" | openssl dgst -sha256 -hmac "$RZP_SECRET" -hex | awk '{print $NF}'; }
verify() { # $1 token $2 order id $3 rzp order id $4 payment id $5 signature -> VCODE, V
  VCODE=$(curl -s -o "$TMP/v.json" -w '%{http_code}' -X POST "$BASE_URL/api/orders/$2/verify" -H "Content-Type: application/json" ${1:+-H "Authorization: Bearer $1"} \
    -d "{\"razorpay_order_id\":\"$3\",\"razorpay_payment_id\":\"$4\",\"razorpay_signature\":\"$5\"}")
  V=$(cat "$TMP/v.json")
}
get_json() { # $1 token $2 path -> GCODE, G
  GCODE=$(curl -s -o "$TMP/g.json" -w '%{http_code}' "$BASE_URL$2" ${1:+-H "Authorization: Bearer $1"}); G=$(cat "$TMP/g.json")
}
new_order() { # $1 token $2 json -> NID, NRZP
  post_order "$1" "$2"
  NID=$(echo "$O" | grep -o '"order":{"id":[0-9]*' | grep -o '[0-9]*$')
  NRZP=$(echo "$O" | grep -o '"orderId":"[^"]*"' | cut -d'"' -f4)
}
jh() { echo "$1" | grep -Eq "$2"; }
order_status() { sql "SELECT status FROM orders WHERE id=$1"; }
count_of() { echo "$1" | grep -o "$2" | wc -l | tr -d ' '; }

read -r TOKEN_C UID_C <<<"$(register "ordertest-c-$STAMP@example.com")"
read -r TOKEN_D UID_D <<<"$(register "ordertest-d-$STAMP@example.com")"
sql "DELETE FROM rate_limits WHERE bucket IN ('order-create:$UID_C','order-create:$UID_D');"
DIG="{\"bookId\":$BOOK_ID,\"purchaseType\":\"digital\"}"
new_order "$TOKEN_C" "$DIG"; O1=$NID; R1=$NRZP
new_order "$TOKEN_C" "$DIG"; O2=$NID; R2=$NRZP
new_order "$TOKEN_C" "$DIG"; O3=$NID; R3=$NRZP
new_order "$TOKEN_C" "$DIG"; O4=$NID; R4=$NRZP
new_order "$TOKEN_C" "$DIG"; O5=$NID; R5=$NRZP
new_order "$TOKEN_C" "{\"bookId\":$BOOK_ID,\"purchaseType\":\"hardcopy\",\"address\":$ADDR}"; OH=$NID; RH=$NRZP
new_order "$TOKEN_D" "$DIG"; OD=$NID
check "section 4 fixtures created (6 orders for C, 1 for D)" "$([ -n "$O1" ] && [ -n "$O5" ] && [ -n "$OH" ] && [ -n "$OD" ] && [ -n "$R1" ] && [ -n "$RH" ] && [ -n "$UID_C" ] && echo true || echo false)"

# Rejections first (none of these may change an order)
P1="pay_fake_${R1}_captured"; S1=$(sign "$R1" "$P1")
verify "" "$O1" "$R1" "$P1" "$S1"
check "verify without token -> 401" "$([ "$VCODE" = "401" ] && echo true || echo false)"
verify "$TOKEN_D" "$O1" "$R1" "$P1" "$S1"
check "user D verifying C's order -> 404, order still pending" "$([ "$VCODE" = "404" ] && [ "$(order_status $O1)" = "pending" ] && echo true || echo false)"
verify "$TOKEN_C" "$O2" "$R2" "pay_fake_${R2}_captured" "deadbeef"
check "bad signature -> 400 payment_verification_failed" "$([ "$VCODE" = "400" ] && json_has "$V" '"code":"payment_verification_failed"' || echo false)"
check "bad signature: order pending, no access row, payment not captured" "$([ "$(order_status $O2)" = "pending" ] && [ "$(sql "SELECT COUNT(*) FROM book_access WHERE user_id=$UID_C AND book_id=$BOOK_ID")" = "0" ] && [ "$(sql "SELECT status FROM payments WHERE order_id=$O2")" = "created" ] && echo true || echo false)"
verify "$TOKEN_C" "$O2" "$R3" "pay_fake_${R3}_captured" "$(sign "$R3" "pay_fake_${R3}_captured")"
check "valid signature for a different razorpay_order_id -> 400, order still pending" "$([ "$VCODE" = "400" ] && jh "$V" '"code":"payment_verification_failed"' && [ "$(order_status $O2)" = "pending" ] && echo true || echo false)"
verify "$TOKEN_C" "$O2" "$R2" "pay_fake_${R3}_captured" "$(sign "$R2" "pay_fake_${R3}_captured")"
check "signed payment from another gateway order -> 400, order still pending" "$([ "$VCODE" = "400" ] && [ "$(order_status $O2)" = "pending" ] && echo true || echo false)"
verify "$TOKEN_C" "$O2" "" "" ""
check "missing verify fields -> 400" "$([ "$VCODE" = "400" ] && echo true || echo false)"
verify "$TOKEN_C" "99999999" "$R2" "$P1" "$S1"
check "verify unknown order -> 404" "$([ "$VCODE" = "404" ] && echo true || echo false)"

# Captured digital: paid, but NO book_access (access arrives via code redemption)
verify "$TOKEN_C" "$O1" "$R1" "$P1" "$S1"
check "captured digital verify -> 200, status paid, paidAt set" "$([ "$VCODE" = "200" ] && json_has "$V" '^\{"order":\{"id":'"$O1"',.*"status":"paid".*"paidAt":"[0-9]' || echo false)"
check "digital paid: payment captured with id stored, NO book_access row" "$([ "$(sql "SELECT CONCAT(status,'|',razorpay_payment_id) FROM payments WHERE order_id=$O1")" = "captured|$P1" ] && [ "$(sql "SELECT COUNT(*) FROM book_access WHERE user_id=$UID_C AND book_id=$BOOK_ID")" = "0" ] && echo true || echo false)"
verify "$TOKEN_C" "$O1" "$R1" "$P1" "$S1"
check "repeat verify -> 200 idempotent, still paid" "$([ "$VCODE" = "200" ] && json_has "$V" '"status":"paid"' || echo false)"
verify "$TOKEN_C" "$O1" "$R1" "$P1" "bad"
check "repeat verify with bad signature on paid order -> still 400" "$([ "$VCODE" = "400" ] && echo true || echo false)"

# Failed and authorized
P4="pay_fake_${R4}_failed"
verify "$TOKEN_C" "$O4" "$R4" "$P4" "$(sign "$R4" "$P4")"
check "failed payment -> 200, status failed" "$([ "$VCODE" = "200" ] && jh "$V" '"status":"failed"' && [ "$(order_status $O4)" = "failed" ] && echo true || echo false)"
P5="pay_fake_${R5}_authorized"
verify "$TOKEN_C" "$O5" "$R5" "$P5" "$(sign "$R5" "$P5")"
check "authorized payment -> 202, order still pending" "$([ "$VCODE" = "202" ] && jh "$V" '"status":"pending"' && [ "$(order_status $O5)" = "pending" ] && echo true || echo false)"

# Hard copy
PH="pay_fake_${RH}_captured"
verify "$TOKEN_C" "$OH" "$RH" "$PH" "$(sign "$RH" "$PH")"
check "hardcopy verify -> 200, paid, fulfilmentStatus confirmed" "$([ "$VCODE" = "200" ] && jh "$V" '"status":"paid"' && jh "$V" '"fulfilmentStatus":"confirmed"' && echo true || echo false)"

# Customer order APIs
get_json "$TOKEN_C" "/api/me/orders"
check "GET /api/me/orders -> 200 with C's 6 orders, none of D's" "$([ "$GCODE" = "200" ] && [ "$(count_of "$G" '"orderNumber"')" = "6" ] && ! echo "$G" | grep -q "\"id\":$OD," && echo true || echo false)"
check "orders list newest first (hardcopy order leads)" "$(json_has "$G" '^\[\{"id":'"$OH"',')"
get_json "$TOKEN_D" "/api/me/orders"
check "D's list has exactly D's one order" "$([ "$(count_of "$G" '"orderNumber"')" = "1" ] && json_has "$G" "\"id\":$OD," || echo false)"
get_json "" "/api/me/orders"
check "orders list without token -> 401" "$([ "$GCODE" = "401" ] && echo true || echo false)"
get_json "$TOKEN_C" "/api/me/orders/$OH"
check "GET /api/me/orders/{hardcopy} includes address, fulfilmentStatus confirmed" "$([ "$GCODE" = "200" ] && json_has "$G" '"fulfilmentStatus":"confirmed".*"address":\{.*"fullName":"Asha Kumar".*"pincode":"625001"' || echo false)"
get_json "$TOKEN_C" "/api/me/orders/$O1"
check "GET /api/me/orders/{digital} -> 200 paid, no address" "$([ "$GCODE" = "200" ] && jh "$G" '"status":"paid"' && ! echo "$G" | grep -q '"address"' && echo true || echo false)"
get_json "$TOKEN_D" "/api/me/orders/$O1"
check "GET /api/me/orders/{C's} as D -> 404" "$([ "$GCODE" = "404" ] && echo true || echo false)"
get_json "$TOKEN_C" "/api/me/orders/99999999"
check "GET /api/me/orders/{missing} -> 404" "$([ "$GCODE" = "404" ] && echo true || echo false)"

# Task 7 review gaps: role and body-shape guards
verify "$ADMIN_TOKEN" "$O5" "$R5" "$P5" "x"
check "admin token on verify -> 403" "$([ "$VCODE" = "403" ] && echo true || echo false)"
get_json "$ADMIN_TOKEN" "/api/me/orders"
check "admin token on GET /api/me/orders -> 403" "$([ "$GCODE" = "403" ] && echo true || echo false)"
get_json "$ADMIN_TOKEN" "/api/me/orders/$O1"
check "admin token on GET /api/me/orders/{id} -> 403" "$([ "$GCODE" = "403" ] && echo true || echo false)"
VCODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE_URL/api/orders/$O5/verify" -H "Content-Type: application/json" -H "Authorization: Bearer $TOKEN_C" \
  -d '{"razorpay_order_id":["x"],"razorpay_payment_id":1,"razorpay_signature":null}')
check "verify with non-string JSON fields -> 400" "$([ "$VCODE" = "400" ] && echo true || echo false)"

echo "=== 5. Razorpay webhook ==="
hmac() { printf '%s' "$1" | openssl dgst -sha256 -hmac "$WH_SECRET" -hex | awk '{print $NF}'; }
wh() { # $1 event id ("" omits header) $2 raw body [$3 signature override; "none" omits the header] -> WCODE, W
  local sig="${3:-$(hmac "$2")}" args=()
  [ -n "$1" ] && args+=(-H "X-Razorpay-Event-Id: $1")
  [ "$sig" != "none" ] && args+=(-H "X-Razorpay-Signature: $sig")
  WCODE=$(curl -s -o "$TMP/w.json" -w '%{http_code}' -X POST "$BASE_URL/api/webhooks/razorpay" -H "Content-Type: application/json" "${args[@]}" --data-binary "$2")
  W=$(cat "$TMP/w.json")
}
cap_body() { echo "{\"event\":\"payment.captured\",\"payload\":{\"payment\":{\"entity\":{\"id\":\"$2\",\"order_id\":\"$1\",\"amount\":$3,\"status\":\"captured\"}}}}"; }
fail_body() { echo "{\"event\":\"payment.failed\",\"payload\":{\"payment\":{\"entity\":{\"id\":\"$2\",\"order_id\":\"$1\",\"amount\":19900,\"status\":\"failed\",\"error_description\":\"card declined\"}}}}"; }
processed() { sql "SELECT processed_at IS NOT NULL FROM payment_events WHERE event_id='$1'"; }
commissions_of() { sql "SELECT COUNT(*) FROM commissions WHERE order_id=$1"; }
EV="evt_ordertest_$STAMP"
REFUND_BODY='{"event":"refund.processed","payload":{"refund":{"entity":{"id":"rfnd_1","payment_id":"pay_x"}}}}'

read -r TOKEN_E UID_E <<<"$(register "ordertest-e-$STAMP@example.com")"
sql "DELETE FROM rate_limits WHERE bucket='order-create:$UID_E';"
new_order "$TOKEN_E" "{\"bookId\":$BOOK_ID,\"purchaseType\":\"digital\",\"referralCode\":\"VTP-TEST01\"}"; W1=$NID; WR1=$NRZP
new_order "$TOKEN_E" "$DIG"; W2=$NID; WR2=$NRZP
new_order "$TOKEN_E" "$DIG"; W3=$NID; WR3=$NRZP
new_order "$TOKEN_E" "$DIG"; W4=$NID; WR4=$NRZP
new_order "$TOKEN_E" "{\"bookId\":$BOOK_ID,\"purchaseType\":\"hardcopy\",\"address\":$ADDR}"; WH=$NID; WRH=$NRZP
check "section 5 fixtures created" "$([ -n "$W1" ] && [ -n "$W4" ] && [ -n "$WH" ] && [ -n "$WR1" ] && [ -n "$WRH" ] && [ "$(sql "SELECT referral_partner_id IS NOT NULL FROM orders WHERE id=$W1")" = "1" ] && echo true || echo false)"

# Rejections write nothing
B1=$(cap_body "$WR1" "pay_wh_1" 19900)
wh "${EV}_bad" "$B1" "deadbeef"
check "bad signature -> 400 invalid_signature" "$([ "$WCODE" = "400" ] && jh "$W" '"code":"invalid_signature"' && echo true || echo false)"
wh "${EV}_nosig" "$B1" "none"
check "missing signature -> 400 invalid_signature" "$([ "$WCODE" = "400" ] && jh "$W" '"code":"invalid_signature"' && echo true || echo false)"
wh "${EV}_tamper" "$(cap_body "$WR1" "pay_wh_1" 1)" "$(hmac "$B1")"
check "signature over a different body -> 400" "$([ "$WCODE" = "400" ] && echo true || echo false)"
wh "" "$B1"
check "missing event id (valid signature) -> 400 validation_error" "$([ "$WCODE" = "400" ] && jh "$W" '"code":"validation_error"' && echo true || echo false)"
wh "${EV}_junk" "not json"
check "invalid JSON (valid signature) -> 400" "$([ "$WCODE" = "400" ] && echo true || echo false)"
check "rejected webhooks: order still pending, nothing recorded" "$([ "$(order_status $W1)" = "pending" ] && [ "$(sql "SELECT COUNT(*) FROM payment_events WHERE event_id LIKE '${EV}_%'")" = "0" ] && echo true || echo false)"

# Captured WITHOUT a prior verify call (referral order)
wh "${EV}_c1" "$B1"
check "captured webhook -> 200 ok" "$([ "$WCODE" = "200" ] && jh "$W" '"status":"ok"' && echo true || echo false)"
check "order paid, payment captured with webhook payment id, no book_access" "$([ "$(order_status $W1)" = "paid" ] && [ "$(sql "SELECT CONCAT(status,'|',razorpay_payment_id) FROM payments WHERE order_id=$W1")" = "captured|pay_wh_1" ] && [ "$(sql "SELECT COUNT(*) FROM book_access WHERE user_id=$UID_E AND book_id=$BOOK_ID")" = "0" ] && echo true || echo false)"
check "referral order has exactly one commission; event marked processed" "$([ "$(commissions_of $W1)" = "1" ] && [ "$(processed ${EV}_c1)" = "1" ] && echo true || echo false)"
wh "${EV}_c1" "$B1"
check "same event id replayed -> 200 duplicate, still one commission" "$([ "$WCODE" = "200" ] && jh "$W" '"status":"duplicate"' && [ "$(commissions_of $W1)" = "1" ] && echo true || echo false)"
wh "${EV}_c1b" "$B1"
check "different event id, same payment -> 200 ok, one commission, no flag" "$([ "$WCODE" = "200" ] && jh "$W" '"status":"ok"' && [ "$(commissions_of $W1)" = "1" ] && [ "$(sql "SELECT admin_flag IS NULL FROM orders WHERE id=$W1")" = "1" ] && echo true || echo false)"
wh "${EV}_c1c" "$(cap_body "$WR1" "pay_wh_other" 19900)"
check "second capture with another payment id -> admin_flag duplicate_payment, original payment kept" "$([ "$WCODE" = "200" ] && [ "$(sql "SELECT admin_flag FROM orders WHERE id=$W1")" = "duplicate_payment" ] && [ "$(sql "SELECT razorpay_payment_id FROM payments WHERE order_id=$W1")" = "pay_wh_1" ] && [ "$(commissions_of $W1)" = "1" ] && echo true || echo false)"

# Failed
wh "${EV}_f2" "$(fail_body "$WR2" "pay_wh_2")"
check "payment.failed for pending order -> 200, order failed with reason" "$([ "$WCODE" = "200" ] && [ "$(order_status $W2)" = "failed" ] && [ "$(sql "SELECT failure_reason FROM payments WHERE order_id=$W2")" = "card declined" ] && echo true || echo false)"
wh "${EV}_f1" "$(fail_body "$WR1" "pay_wh_late")"
check "payment.failed after capture -> 200, order stays paid" "$([ "$WCODE" = "200" ] && [ "$(order_status $W1)" = "paid" ] && echo true || echo false)"

# Amount mismatch
wh "${EV}_m3" "$(cap_body "$WR3" "pay_wh_3" 100)"
check "amount mismatch -> 200 flagged" "$([ "$WCODE" = "200" ] && jh "$W" '"status":"flagged"' && echo true || echo false)"
check "mismatch: order pending, admin_flag amount_mismatch, event processed" "$([ "$(order_status $W3)" = "pending" ] && [ "$(sql "SELECT admin_flag FROM orders WHERE id=$W3")" = "amount_mismatch" ] && [ "$(processed ${EV}_m3)" = "1" ] && echo true || echo false)"
check "mismatch: full webhook payload stored for inspection" "$([ "$(sql "SELECT JSON_EXTRACT(payload,'\$.payload.payment.entity.amount') FROM payment_events WHERE event_id='${EV}_m3'")" = "100" ] && echo true || echo false)"

# Ignored events and unknown orders
wh "${EV}_r4" "$REFUND_BODY"
check "refund.processed -> 200 ignored" "$([ "$WCODE" = "200" ] && jh "$W" '"status":"ignored"' && echo true || echo false)"
check "refund.processed recorded and processed" "$([ "$(sql "SELECT event_type FROM payment_events WHERE event_id='${EV}_r4'")" = "refund.processed" ] && [ "$(processed ${EV}_r4)" = "1" ] && echo true || echo false)"
wh "${EV}_u1" "$(cap_body "order_does_not_exist" "pay_wh_u" 19900)"
check "captured for unknown razorpay order -> 200 ignored" "$([ "$WCODE" = "200" ] && jh "$W" '"status":"ignored"' && echo true || echo false)"
check "captured for unknown razorpay order is logged (ids only)" "$(docker compose logs --since 2m php 2>&1 | grep -q 'unknown razorpay order (razorpay_order_id=order_does_not_exist, payment_id=pay_wh_u)' && echo true || echo false)"

# Retry of an unprocessed event
sql "UPDATE payment_events SET processed_at=NULL WHERE event_id='${EV}_r4';"
wh "${EV}_r4" "$REFUND_BODY"
check "event with processed_at NULL is reprocessed (ignored, not duplicate) and marked processed" "$([ "$WCODE" = "200" ] && jh "$W" '"status":"ignored"' && [ "$(processed ${EV}_r4)" = "1" ] && echo true || echo false)"
sql "INSERT INTO payment_events (event_id, event_type, payload) VALUES ('${EV}_p4', 'payment.captured', NULL);"
wh "${EV}_p4" "$(cap_body "$WR4" "pay_wh_4" 19900)"
check "pre-existing unprocessed event row: reprocessed -> order paid, processed_at set" "$([ "$WCODE" = "200" ] && jh "$W" '"status":"ok"' && [ "$(order_status $W4)" = "paid" ] && [ "$(processed ${EV}_p4)" = "1" ] && echo true || echo false)"

# Hard copy via webhook
wh "${EV}_h1" "$(cap_body "$WRH" "pay_wh_h" 34900)"
check "hardcopy captured webhook -> paid, fulfilment confirmed" "$([ "$WCODE" = "200" ] && [ "$(sql "SELECT CONCAT(status,'|',fulfilment_status) FROM orders WHERE id=$WH")" = "paid|confirmed" ] && echo true || echo false)"
check "webhook-paid digital order has one active access code, hardcopy has none" "$([ "$(sql "SELECT COUNT(*) FROM access_codes WHERE order_id=$W1 AND status='active'")" = "1" ] && [ "$(sql "SELECT COUNT(*) FROM access_codes WHERE order_id=$WH")" = "0" ] && echo true || echo false)"

echo "=== 6. Access codes, library, tracking ==="
post_json() { # $1 token $2 path $3 json -> PCODE, P
  PCODE=$(curl -s -o "$TMP/p.json" -w '%{http_code}' -X POST "$BASE_URL$2" -H "Content-Type: application/json" ${1:+-H "Authorization: Bearer $1"} -d "$3"); P=$(cat "$TMP/p.json")
}
code_of() { echo "$1" | grep -o '"code":"[^"]*"' | cut -d'"' -f4; }
sql "DELETE FROM rate_limits WHERE bucket LIKE 'code-redeem-%' OR bucket LIKE 'code-reissue:%' OR bucket LIKE 'track:%';"
read -r TOKEN_F UID_F <<<"$(register "ordertest-f-$STAMP@example.com")"
read -r TOKEN_G UID_G <<<"$(register "ordertest-g-$STAMP@example.com")"
read -r TOKEN_H UID_H <<<"$(register "ordertest-h-$STAMP@example.com")"
new_order "$TOKEN_F" "$DIG"; CF=$NID; CRF=$NRZP
PF="pay_fake_${CRF}_captured"
verify "$TOKEN_F" "$CF" "$CRF" "$PF" "$(sign "$CRF" "$PF")"
check "section 6: digital order paid via verify" "$([ "$VCODE" = "200" ] && [ -n "$UID_H" ] && jh "$V" '"digitalCode":\{"status":"active","last4":"[0-9A-Z]{4}"\}' && echo true || echo false)"

get_json "$TOKEN_F" "/api/me/orders/$CF/access-code"
DCODE=$(code_of "$G")
check "owner reveals access code XXXX-XXXX-XXXX-XXXX" "$([ "$GCODE" = "200" ] && echo "$DCODE" | grep -Eq '^([0-9A-HJKMNP-TV-Z]{4}-){3}[0-9A-HJKMNP-TV-Z]{4}$' && echo true || echo false)"
get_json "$TOKEN_F" "/api/me/orders/$CF"
check "order view: digitalCode last4 only, never plaintext; trackingCode null" "$(jh "$G" "\"last4\":\"${DCODE: -4}\"" && ! echo "$G" | grep -q "${DCODE:0:9}" && jh "$G" '"trackingCode":null' && echo true || echo false)"
get_json "$TOKEN_G" "/api/me/orders/$CF/access-code"
check "other user revealing F's code -> 404" "$([ "$GCODE" = "404" ] && echo true || echo false)"
get_json "" "/api/me/orders/$CF/access-code"
check "reveal without token -> 401" "$([ "$GCODE" = "401" ] && echo true || echo false)"

get_json "$TOKEN_F" "/api/me/library"
check "library: order pending redemption, no books, no book_access row" "$([ "$GCODE" = "200" ] && jh "$G" '^\{"books":\[\],"pendingRedemptions":\[\{"orderId":'"$CF"',"bookId":'"$BOOK_ID"',"title":"Order Test Book"' && [ "$(sql "SELECT COUNT(*) FROM book_access WHERE user_id=$UID_F")" = "0" ] && echo true || echo false)"

post_json "$TOKEN_F" "/api/me/redeem" "{\"code\":\"$(echo "$DCODE" | tr 'A-Z' 'a-z')\"}"
check "owner redeems (lowercase) -> 200 {bookId, orderId}" "$([ "$PCODE" = "200" ] && jh "$P" "^\{\"bookId\":$BOOK_ID,\"orderId\":$CF\}$" && echo true || echo false)"
get_json "$TOKEN_F" "/api/me/library"
check "library now lists the book active, nothing pending" "$(jh "$G" '^\{"books":\[\{"bookId":'"$BOOK_ID"',"title":"Order Test Book","coverUrl":null,"orderId":'"$CF"',"purchasedAt":"[0-9].*"accessStatus":"active"\}\],"pendingRedemptions":\[\]\}$' && echo true || echo false)"
check "code_enc cleared after redemption" "$([ "$(sql "SELECT code_enc IS NULL FROM access_codes WHERE order_id=$CF")" = "1" ] && echo true || echo false)"
get_json "$TOKEN_F" "/api/me/orders/$CF/access-code"
check "reveal after redemption -> 404" "$([ "$GCODE" = "404" ] && echo true || echo false)"
post_json "$TOKEN_F" "/api/me/redeem" "{\"code\":\"$DCODE\"}"
check "owner redeems again -> 200 idempotent" "$([ "$PCODE" = "200" ] && echo true || echo false)"
check "successful redeems leave no rate-limit hits" "$([ "$(sql "SELECT COALESCE(SUM(hits),0) FROM rate_limits WHERE bucket='code-redeem-user:$UID_F'")" = "0" ] && echo true || echo false)"
post_json "$TOKEN_G" "/api/me/redeem" "{\"code\":\"$DCODE\"}"
check "second user redeems same code -> 409 code_already_redeemed, no access" "$([ "$PCODE" = "409" ] && jh "$P" '"code":"code_already_redeemed"' && [ "$(sql "SELECT COUNT(*) FROM book_access WHERE user_id=$UID_G")" = "0" ] && echo true || echo false)"
post_json "$ADMIN_TOKEN" "/api/me/redeem" "{\"code\":\"$DCODE\"}"
check "admin token on redeem -> 403" "$([ "$PCODE" = "403" ] && echo true || echo false)"
get_json "$ADMIN_TOKEN" "/api/me/orders/$CF/access-code"
check "admin token on GET access-code -> 403" "$([ "$GCODE" = "403" ] && echo true || echo false)"
post_json "$ADMIN_TOKEN" "/api/me/orders/$CF/reissue-code" '{}'
check "admin token on POST reissue-code -> 403" "$([ "$PCODE" = "403" ] && echo true || echo false)"
get_json "$ADMIN_TOKEN" "/api/me/library"
check "admin token on GET library -> 403" "$([ "$GCODE" = "403" ] && echo true || echo false)"

CODES=""
for i in 1 2 3 4 5 6; do
  post_json "$TOKEN_H" "/api/me/redeem" '{"code":"AAAA-BBBB-CCCC-DDDD"}'
  CODES="$CODES $PCODE"
done
check "5 wrong guesses -> 400 code_invalid, 6th -> 429" "$([ "$CODES" = " 400 400 400 400 400 429" ] && jh "$P" '"code":"rate_limited"' && echo true || echo false)"
post_json "$TOKEN_H" "/api/me/redeem" '{}'
check "rate-limited user stays limited (even malformed body)" "$([ "$PCODE" = "429" ] && echo true || echo false)"

# Reissue: owner only, revokes the redeemed code and its access
post_json "$TOKEN_G" "/api/me/orders/$CF/reissue-code" '{}'
check "other user reissuing F's code -> 404" "$([ "$PCODE" = "404" ] && echo true || echo false)"
post_json "$TOKEN_F" "/api/me/orders/$CF/reissue-code" '{}'
NCODE=$(code_of "$P")
check "owner reissue -> 200 new code" "$([ "$PCODE" = "200" ] && [ -n "$NCODE" ] && [ "$NCODE" != "$DCODE" ] && echo true || echo false)"
post_json "$TOKEN_F" "/api/me/redeem" "{\"code\":\"$DCODE\"}"
check "old code after reissue -> 403 code_revoked" "$([ "$PCODE" = "403" ] && jh "$P" '"code":"code_revoked"' && echo true || echo false)"
get_json "$TOKEN_F" "/api/me/library"
check "library: book revoked, order pending again" "$(jh "$G" '"accessStatus":"revoked"' && jh "$G" '"pendingRedemptions":\[\{"orderId":'"$CF" && echo true || echo false)"
check "reissue audit row: user_id set, admin_id NULL" "$([ "$(sql "SELECT COUNT(*) FROM audit_logs WHERE action='access_code.reissue' AND user_id=$UID_F AND admin_id IS NULL")" = "1" ] && echo true || echo false)"
post_json "$TOKEN_F" "/api/me/orders/$CF/reissue-code" '{}'; R2C=$PCODE
post_json "$TOKEN_F" "/api/me/orders/$CF/reissue-code" '{}'; R3C=$PCODE
post_json "$TOKEN_F" "/api/me/orders/$CF/reissue-code" '{}'
check "reissue 3/day: 2nd and 3rd ok, 4th -> 429" "$([ "$R2C" = "200" ] && [ "$R3C" = "200" ] && [ "$PCODE" = "429" ] && echo true || echo false)"
check "audit log never stores a plaintext code" "$([ "$(sql "SELECT COUNT(*) FROM audit_logs WHERE CAST(detail AS CHAR) LIKE '%${NCODE:0:4}%${NCODE:5:4}%' OR CAST(detail AS CHAR) LIKE '%${DCODE:0:4}%${DCODE:5:4}%'")" = "0" ] && echo true || echo false)"

# Hard copy: tracking code, public lookup, separate from digital access
new_order "$TOKEN_F" "{\"bookId\":$BOOK_ID,\"purchaseType\":\"hardcopy\",\"address\":$ADDR}"; TH=$NID; TRH=$NRZP
PTH="pay_fake_${TRH}_captured"
verify "$TOKEN_F" "$TH" "$TRH" "$PTH" "$(sign "$TRH" "$PTH")"
TCODE=$(echo "$V" | grep -o '"trackingCode":"[^"]*"' | cut -d'"' -f4)
check "hardcopy owner sees trackingCode VTT-XXXXXXXX, digitalCode null" "$(echo "$TCODE" | grep -Eq '^VTT-[0-9A-HJKMNP-TV-Z]{8}$' && jh "$V" '"digitalCode":null' && echo true || echo false)"
get_json "" "/api/track/$TCODE"
check "GET /api/track/{code} -> 200 confirmed with label" "$([ "$GCODE" = "200" ] && jh "$G" '^\{"orderNumber":"VT-[0-9]{8}-[0-9A-Z]{6}","bookTitle":"Order Test Book","status":"confirmed","label":"Order Confirmed","updatedAt":"[0-9]' && echo true || echo false)"
check "track response has no address, email or code keys" "$(! echo "$G" | grep -Eqi '"(address|email|fullName|phone|pincode|code|digitalCode|trackingCode)"' && echo true || echo false)"
get_json "" "/api/track/$(echo "$TCODE" | tr 'A-Z' 'a-z')"
check "track lookup is case-insensitive" "$([ "$GCODE" = "200" ] && echo true || echo false)"
post_json "$TOKEN_F" "/api/me/redeem" "{\"code\":\"$TCODE\"}"
check "tracking code on /api/me/redeem -> 400 code_invalid" "$([ "$PCODE" = "400" ] && jh "$P" '"code":"code_invalid"' && echo true || echo false)"
get_json "$TOKEN_F" "/api/me/orders/$TH/access-code"
check "hardcopy order has no access code -> 404" "$([ "$GCODE" = "404" ] && echo true || echo false)"
get_json "" "/api/track/VTT-00000000"
check "unknown tracking code -> 404 not_found" "$([ "$GCODE" = "404" ] && jh "$G" '"code":"not_found"' && echo true || echo false)"

# Track limit: 10/min per IP (fixed 60s window; avoid straddling the boundary)
SEC=$((10#$(date +%S)))
[ "$SEC" -gt 45 ] && sleep $((61 - SEC))
sql "DELETE FROM rate_limits WHERE bucket LIKE 'track:%';"
CODES=""
for i in $(seq 1 11); do
  CODES="$CODES $(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/api/track/VTT-00000000")"
done
check "track: 10 lookups a minute -> 404 each, 11th -> 429" "$([ "$CODES" = " 404 404 404 404 404 404 404 404 404 404 429" ] && echo true || echo false)"
sql "DELETE FROM rate_limits WHERE bucket LIKE 'track:%';"

echo "=== 7. Refunds ==="
# Reader 403 after refund arrives with Task 17; here: book_access revoked + library shows it.
paid_order() { # $1 token $2 json -> NID, NRZP, NPAY (paid via verify)
  new_order "$1" "$2"; NPAY="pay_fake_${NRZP}_captured"
  verify "$1" "$NID" "$NRZP" "$NPAY" "$(sign "$NRZP" "$NPAY")"
}
rf_body() { echo "{\"event\":\"refund.processed\",\"payload\":{\"refund\":{\"entity\":{\"id\":\"$1\",\"payment_id\":\"$2\",\"amount\":$3,\"status\":\"processed\"}}}}"; }
id_of() { echo "$1" | grep -o '"refund":{"id":[0-9]*' | grep -o '[0-9]*$'; }
sql "DELETE FROM rate_limits WHERE bucket LIKE 'code-redeem-%' OR bucket LIKE 'code-reissue:%';"
read -r TOKEN_J UID_J <<<"$(register "ordertest-j-$STAMP@example.com")"
read -r TOKEN_K UID_K <<<"$(register "ordertest-k-$STAMP@example.com")"
sql "DELETE FROM rate_limits WHERE bucket IN ('order-create:$UID_J','order-create:$UID_K');"
paid_order "$TOKEN_J" "{\"bookId\":$BOOK_ID,\"purchaseType\":\"digital\",\"referralCode\":\"VTP-TEST01\"}"; JD=$NID; JPAY=$NPAY
check "section 7: referral digital order paid, commission pending" "$([ "$VCODE" = "200" ] && [ "$(sql "SELECT status FROM commissions WHERE order_id=$JD")" = "pending" ] && echo true || echo false)"
get_json "$TOKEN_J" "/api/me/orders/$JD/access-code"; JCODE=$(code_of "$G")
post_json "$TOKEN_J" "/api/me/redeem" "{\"code\":\"$JCODE\"}"
check "section 7: code redeemed, access active" "$([ "$PCODE" = "200" ] && [ "$(sql "SELECT revoked_at IS NULL FROM book_access WHERE user_id=$UID_J AND book_id=$BOOK_ID")" = "1" ] && echo true || echo false)"

RQ="/api/me/orders/$JD/refund-request"
post_json "$TOKEN_J" "$RQ" '{}'
check "refund request without reason -> 400 fields.reason" "$([ "$PCODE" = "400" ] && jh "$P" '"code":"validation_error"' && jh "$P" '"fields":\{"reason"' && echo true || echo false)"
post_json "$TOKEN_J" "$RQ" '{"reason":5}'; C1=$PCODE
post_json "$TOKEN_J" "$RQ" "{\"reason\":\"$(printf 'x%.0s' $(seq 1 501))\"}"
check "non-string or 501-char reason -> 400" "$([ "$C1" = "400" ] && [ "$PCODE" = "400" ] && echo true || echo false)"
post_json "" "$RQ" '{"reason":"x"}'; C1=$PCODE
post_json "$ADMIN_TOKEN" "$RQ" '{"reason":"x"}'; C2=$PCODE
post_json "$TOKEN_K" "$RQ" '{"reason":"x"}'
check "refund request: no token 401, admin 403, other customer 404" "$([ "$C1" = "401" ] && [ "$C2" = "403" ] && [ "$PCODE" = "404" ] && echo true || echo false)"
check "rejected requests wrote no refund rows" "$([ "$(sql "SELECT COUNT(*) FROM refunds WHERE order_id=$JD")" = "0" ] && echo true || echo false)"
post_json "$TOKEN_J" "$RQ" '{"reason":"  Bought by mistake  "}'; RF1=$(id_of "$P")
check "refund request -> 201 requested, amount from payment" "$([ "$PCODE" = "201" ] && jh "$P" "^\{\"refund\":\{\"id\":$RF1,\"orderId\":$JD,\"status\":\"requested\",\"reason\":\"Bought by mistake\",\"amount\":199(\.0)?,\"createdAt\":\"[0-9][^\"]*\",\"adminNote\":null\}\}$" && echo true || echo false)"
post_json "$TOKEN_J" "$RQ" '{"reason":"again"}'
check "duplicate request -> 200 same refund, one row" "$([ "$PCODE" = "200" ] && [ "$(id_of "$P")" = "$RF1" ] && [ "$(sql "SELECT COUNT(*) FROM refunds WHERE order_id=$JD")" = "1" ] && echo true || echo false)"
get_json "$TOKEN_J" "/api/me/refunds"; G1=$G; C1=$GCODE
get_json "$TOKEN_K" "/api/me/refunds"; C2=$GCODE; G2=$G
get_json "$ADMIN_TOKEN" "/api/me/refunds"
check "GET /api/me/refunds: own only (K sees []), admin 403" "$([ "$C1" = "200" ] && jh "$G1" "^\[\{\"id\":$RF1,\"orderId\":$JD," && [ "$C2" = "200" ] && [ "$G2" = "[]" ] && [ "$GCODE" = "403" ] && echo true || echo false)"

get_json "" "/api/admin/refunds"; C1=$GCODE
get_json "$TOKEN_J" "/api/admin/refunds"; C2=$GCODE
get_json "$ADMIN_TOKEN" "/api/admin/refunds?status=requested"
check "admin refund list: 401/403 for others, admin sees the request" "$([ "$C1" = "401" ] && [ "$C2" = "403" ] && [ "$GCODE" = "200" ] && jh "$G" "\{\"id\":$RF1,\"orderId\":$JD,\"orderNumber\":\"VT-[^\"]*\",\"purchaseType\":\"digital\",\"userId\":$UID_J,\"status\":\"requested\"" && echo true || echo false)"
get_json "$ADMIN_TOKEN" "/api/admin/refunds?status=bogus"
check "admin refund list ?status=bogus -> 400" "$([ "$GCODE" = "400" ] && jh "$G" '"fields":\{"status"' && echo true || echo false)"
post_json "$ADMIN_TOKEN" "/api/admin/refunds/$RF1/reject" '{"note":"  "}'
check "reject without note -> 400 fields.note, refund unchanged" "$([ "$PCODE" = "400" ] && jh "$P" '"fields":\{"note"' && [ "$(sql "SELECT status FROM refunds WHERE id=$RF1")" = "requested" ] && echo true || echo false)"
post_json "$TOKEN_J" "/api/admin/refunds/$RF1/approve" '{}'
check "customer token on approve -> 403" "$([ "$PCODE" = "403" ] && [ "$(sql "SELECT status FROM refunds WHERE id=$RF1")" = "requested" ] && echo true || echo false)"
post_json "$ADMIN_TOKEN" "/api/admin/refunds/999999999/approve" '{}'
check "approve unknown refund -> 404" "$([ "$PCODE" = "404" ] && echo true || echo false)"

post_json "$ADMIN_TOKEN" "/api/admin/refunds/$RF1/approve" '{"note":"internal: checked"}'
RZRF=$(echo "$P" | grep -o '"razorpayRefundId":"[^"]*"' | cut -d'"' -f4)
check "admin approve -> 200 refunded with rfnd_fake_ id" "$([ "$PCODE" = "200" ] && jh "$P" '"status":"refunded"' && echo "$RZRF" | grep -Eq '^rfnd_fake_[0-9a-f]+$' && echo true || echo false)"
get_json "$TOKEN_J" "/api/me/orders/$JD"
check "customer sees order refunded" "$([ "$GCODE" = "200" ] && jh "$G" '"status":"refunded"' && echo true || echo false)"
check "payment refunded; code revoked (refund), code_enc NULL" "$([ "$(sql "SELECT status FROM payments WHERE order_id=$JD")" = "refunded" ] && [ "$(sql "SELECT CONCAT(status,'|',revoked_reason,'|',code_enc IS NULL) FROM access_codes WHERE order_id=$JD ORDER BY id DESC LIMIT 1")" = "revoked|refund|1" ] && echo true || echo false)"
check "customer's book_access.revoked_at set" "$([ "$(sql "SELECT revoked_at IS NOT NULL FROM book_access WHERE user_id=$UID_J AND book_id=$BOOK_ID")" = "1" ] && echo true || echo false)"
check "commission reversed with reversed_at" "$([ "$(sql "SELECT CONCAT(status,'|',reversed_at IS NOT NULL) FROM commissions WHERE order_id=$JD")" = "reversed|1" ] && echo true || echo false)"
check "audit rows refund.approve + refund.refunded carry admin_id" "$([ "$(sql "SELECT COUNT(*) FROM audit_logs WHERE entity_type='refund' AND entity_id=$RF1 AND admin_id IS NOT NULL AND action IN ('refund.approve','refund.refunded')")" = "2" ] && echo true || echo false)"
get_json "$TOKEN_J" "/api/me/library"
check "library: book revoked, refunded order not pending redemption" "$([ "$GCODE" = "200" ] && jh "$G" "\"orderId\":$JD,[^}]*\"accessStatus\":\"revoked\"" && jh "$G" '"pendingRedemptions":\[\]' && echo true || echo false)"
get_json "$TOKEN_J" "/api/me/orders/$JD/access-code"; C1=$GCODE
post_json "$TOKEN_J" "/api/me/orders/$JD/reissue-code" '{}'; C2=$PCODE
post_json "$TOKEN_J" "/api/me/redeem" "{\"code\":\"$JCODE\"}"
check "after refund: reveal 404, reissue 404, redeem 403 code_revoked" "$([ "$C1" = "404" ] && [ "$C2" = "404" ] && [ "$PCODE" = "403" ] && jh "$P" '"code":"code_revoked"' && echo true || echo false)"
get_json "$TOKEN_J" "/api/me/refunds"
check "customer refund list: refunded, internal admin note hidden" "$(jh "$G" "\"id\":$RF1,[^}]*\"status\":\"refunded\"" && jh "$G" '"adminNote":null' && ! echo "$G" | grep -q 'internal' && echo true || echo false)"
post_json "$ADMIN_TOKEN" "/api/admin/refunds/$RF1/approve" '{}'
check "approve again -> 200 refunded, same razorpay refund id" "$([ "$PCODE" = "200" ] && [ "$(sql "SELECT razorpay_refund_id FROM refunds WHERE id=$RF1")" = "$RZRF" ] && [ "$(sql "SELECT COUNT(*) FROM refunds WHERE order_id=$JD")" = "1" ] && echo true || echo false)"
post_json "$TOKEN_J" "$RQ" '{"reason":"once more"}'
check "request on refunded order -> 200 the existing refund" "$([ "$PCODE" = "200" ] && [ "$(id_of "$P")" = "$RF1" ] && echo true || echo false)"
post_json "$ADMIN_TOKEN" "/api/admin/refunds/$RF1/reject" '{"note":"too late"}'
check "reject a refunded refund -> 409 invalid_transition" "$([ "$PCODE" = "409" ] && jh "$P" '"code":"invalid_transition"' && echo true || echo false)"
wh "${EV}_rf1" "$(rf_body "$RZRF" "$JPAY" 19900)"; C1=$WCODE; W1B=$W
wh "${EV}_rf1" "$(rf_body "$RZRF" "$JPAY" 19900)"
check "refund.processed for a refunded refund -> ok; replay -> 200 duplicate" "$([ "$C1" = "200" ] && jh "$W1B" '"status":"ok"' && [ "$WCODE" = "200" ] && jh "$W" '"status":"duplicate"' && echo true || echo false)"
check "webhooks changed nothing: one refund.refunded audit row" "$([ "$(sql "SELECT COUNT(*) FROM audit_logs WHERE entity_type='refund' AND entity_id=$RF1 AND action='refund.refunded'")" = "1" ] && echo true || echo false)"

# Reject: customer sees the admin note on their own rejected refund only
paid_order "$TOKEN_K" "$DIG"; KD=$NID; KPAY=$NPAY
post_json "$TOKEN_K" "/api/me/orders/$KD/refund-request" '{"reason":"changed mind"}'; RFK=$(id_of "$P")
post_json "$ADMIN_TOKEN" "/api/admin/refunds/$RFK/reject" '{"note":"Outside the refund window"}'
check "admin reject -> 200 rejected, order still paid" "$([ "$PCODE" = "200" ] && jh "$P" '"status":"rejected"' && [ "$(order_status $KD)" = "paid" ] && echo true || echo false)"
get_json "$TOKEN_K" "/api/me/refunds"; G1=$G
get_json "$TOKEN_J" "/api/me/refunds"
check "rejected refund shows its note to its owner only" "$(jh "$G1" '"status":"rejected"[^}]*"adminNote":"Outside the refund window"' && ! echo "$G" | grep -q 'Outside' && echo true || echo false)"

# Gateway pending -> processing, finalised by the signed refund.processed webhook
post_json "$TOKEN_K" "/api/me/orders/$KD/refund-request" '{"reason":"please reconsider"}'; RFK2=$(id_of "$P")
check "new request after rejection -> 201" "$([ "$PCODE" = "201" ] && [ -n "$RFK2" ] && [ "$RFK2" != "$RFK" ] && echo true || echo false)"
sql "UPDATE refunds SET status='processing', reviewed_by_admin_id=1, razorpay_refund_id='rfnd_ordertest_$STAMP' WHERE id=$RFK2;"
wh "${EV}_rff1" "$(rf_body "rfnd_ordertest_$STAMP" "$KPAY" 19900 | sed 's/refund.processed/refund.failed/; s/"processed"/"failed"/')"
check "refund.failed (processing) -> ok; refund failed, order paid, code active" "$([ "$WCODE" = "200" ] && jh "$W" '"status":"ok"' && [ "$(sql "SELECT status FROM refunds WHERE id=$RFK2")" = "failed" ] && [ "$(order_status $KD)" = "paid" ] && [ "$(sql "SELECT status FROM access_codes WHERE order_id=$KD ORDER BY id DESC LIMIT 1")" = "active" ] && echo true || echo false)"
wh "${EV}_rff1" "$(rf_body "rfnd_ordertest_$STAMP" "$KPAY" 19900 | sed 's/refund.processed/refund.failed/; s/"processed"/"failed"/')"; C1=$WCODE; W1B=$W
wh "${EV}_rff2" "$(rf_body "rfnd_unknown_$STAMP" "$KPAY" 19900 | sed 's/refund.processed/refund.failed/')"
check "refund.failed replay -> duplicate; unknown refund -> ignored" "$([ "$C1" = "200" ] && jh "$W1B" '"status":"duplicate"' && [ "$WCODE" = "200" ] && jh "$W" '"status":"ignored"' && echo true || echo false)"
sql "UPDATE refunds SET status='processing' WHERE id=$RFK2;" # as if approved again and pending
wh "${EV}_rf2" "$(rf_body "rfnd_ordertest_$STAMP" "$KPAY" 19900)"
check "refund.processed (by refund id) -> ok; refund, order, payment refunded; code revoked" "$([ "$WCODE" = "200" ] && jh "$W" '"status":"ok"' && [ "$(sql "SELECT status FROM refunds WHERE id=$RFK2")" = "refunded" ] && [ "$(order_status $KD)" = "refunded" ] && [ "$(sql "SELECT status FROM payments WHERE order_id=$KD")" = "refunded" ] && [ "$(sql "SELECT status FROM access_codes WHERE order_id=$KD ORDER BY id DESC LIMIT 1")" = "revoked" ] && echo true || echo false)"
wh "${EV}_rf2" "$(rf_body "rfnd_ordertest_$STAMP" "$KPAY" 19900)"
check "replayed refund.processed -> 200 duplicate" "$([ "$WCODE" = "200" ] && jh "$W" '"status":"duplicate"' && echo true || echo false)"

# Fallback by payment id (refund id never stored, e.g. a timed-out call), with an amount guard
ADDR2="{\"bookId\":$BOOK_ID,\"purchaseType\":\"hardcopy\",\"address\":$ADDR}"
paid_order "$TOKEN_J" "$ADDR2"; JH2=$NID; JH2PAY=$NPAY
post_json "$TOKEN_J" "/api/me/orders/$JH2/refund-request" '{"reason":"not needed"}'; RFH2=$(id_of "$P")
# Realistic state: the approve timed out (refund failed, no refund id) and the customer has since asked again.
sql "UPDATE refunds SET status='failed', reviewed_by_admin_id=1 WHERE id=$RFH2;"
post_json "$TOKEN_J" "/api/me/orders/$JH2/refund-request" '{"reason":"asking again"}'; RFH2B=$(id_of "$P")
check "failed refund (no refund id) + newer requested refund on the same order" "$([ "$PCODE" = "201" ] && [ "$(sql "SELECT CONCAT(status,'|',razorpay_refund_id IS NULL) FROM refunds WHERE id=$RFH2")" = "failed|1" ] && [ "$(sql "SELECT status FROM refunds WHERE id=$RFH2B")" = "requested" ] && echo true || echo false)"
wh "${EV}_rf3" "$(rf_body "rfnd_ordertest_b$STAMP" "$JH2PAY" 100)"
check "refund.processed with a different amount -> ignored, refunds unchanged" "$([ "$WCODE" = "200" ] && jh "$W" '"status":"ignored"' && [ "$(sql "SELECT GROUP_CONCAT(status ORDER BY id) FROM refunds WHERE order_id=$JH2")" = "failed,requested" ] && [ "$(order_status $JH2)" = "paid" ] && echo true || echo false)"
wh "${EV}_rf4" "$(rf_body "rfnd_ordertest_b$STAMP" "$JH2PAY" 34900)"
check "refund.processed by payment id -> ok (no 500); the open requested refund refunded with the id, failed one untouched, fulfilment cancelled" "$([ "$WCODE" = "200" ] && jh "$W" '"status":"ok"' && [ "$(sql "SELECT CONCAT(status,'|',razorpay_refund_id) FROM refunds WHERE id=$RFH2B")" = "refunded|rfnd_ordertest_b$STAMP" ] && [ "$(sql "SELECT CONCAT(status,'|',razorpay_refund_id IS NULL) FROM refunds WHERE id=$RFH2")" = "failed|1" ] && [ "$(sql "SELECT COUNT(*) FROM refunds WHERE order_id=$JH2 AND status='refunded'")" = "1" ] && [ "$(sql "SELECT CONCAT(status,'|',fulfilment_status) FROM orders WHERE id=$JH2")" = "refunded|cancelled" ] && echo true || echo false)"
wh "${EV}_rf5" "$(rf_body "rfnd_unknown_$STAMP" "pay_unknown_$STAMP" 100)"
check "refund.processed for an unknown refund -> ignored, logged (ids only)" "$([ "$WCODE" = "200" ] && jh "$W" '"status":"ignored"' && docker compose logs --since 2m php 2>&1 | grep -q "refund.processed for unknown refund (refund_id=rfnd_unknown_$STAMP, payment_id=pay_unknown_$STAMP)" && echo true || echo false)"

# Admin-opened refund on a shipped hard copy
paid_order "$TOKEN_J" "$ADDR2"; JH3=$NID
sql "UPDATE orders SET fulfilment_status='shipped', courier='India Post', tracking_number='EE1', shipped_at=NOW() WHERE id=$JH3;"
post_json "$ADMIN_TOKEN" "/api/admin/orders/$JH3/refund" '{}'; C1=$PCODE
post_json "$TOKEN_J" "/api/admin/orders/$JH3/refund" '{"reason":"x"}'; C2=$PCODE
post_json "$ADMIN_TOKEN" "/api/admin/orders/999999999/refund" '{"reason":"x"}'
check "admin open refund: no reason 400, customer 403, unknown order 404" "$([ "$C1" = "400" ] && [ "$C2" = "403" ] && [ "$PCODE" = "404" ] && echo true || echo false)"
post_json "$ADMIN_TOKEN" "/api/admin/orders/$JH3/refund" '{"reason":"Damaged in transit"}'; RFH3=$(id_of "$P")
check "admin open refund -> 201 refunded; shipped hard copy stays shipped" "$([ "$PCODE" = "201" ] && jh "$P" '"status":"refunded"' && [ "$(sql "SELECT CONCAT(status,'|',fulfilment_status) FROM orders WHERE id=$JH3")" = "refunded|shipped" ] && echo true || echo false)"
check "audit records already-shipped refund" "$([ "$(sql "SELECT COUNT(*) FROM audit_logs WHERE entity_type='refund' AND entity_id=$RFH3 AND action='refund.already_shipped' AND admin_id IS NOT NULL")" = "1" ] && echo true || echo false)"
post_json "$ADMIN_TOKEN" "/api/admin/orders/$JH3/refund" '{"reason":"again"}'
check "admin open refund again -> 200 the same refund" "$([ "$PCODE" = "200" ] && [ "$(id_of "$P")" = "$RFH3" ] && [ "$(sql "SELECT COUNT(*) FROM refunds WHERE order_id=$JH3")" = "1" ] && echo true || echo false)"
new_order "$TOKEN_J" "$ADDR2"; JP=$NID
post_json "$ADMIN_TOKEN" "/api/admin/orders/$JP/refund" '{"reason":"x"}'
check "admin open refund on a pending order -> 409 not_refundable" "$([ "$PCODE" = "409" ] && jh "$P" '"code":"not_refundable"' && echo true || echo false)"
check "no refund response leaks a plaintext code or code_enc" "$(! grep -q "${JCODE:0:9}" "$TMP/p.json" && [ "$(sql "SELECT COUNT(*) FROM audit_logs WHERE entity_type='refund' AND CAST(detail AS CHAR) LIKE '%${JCODE:0:4}%${JCODE:5:4}%'")" = "0" ] && echo true || echo false)"

echo "=== 8. Secure reader (real book 2) ==="
RB=2
hdr_get() { # $1 token $2 path -> RCODE, headers in $TMP/r.h, body in $TMP/r.bin
  RCODE=$(curl -s -D "$TMP/r.h" -o "$TMP/r.bin" -w '%{http_code}' "$BASE_URL$2" ${1:+-H "Authorization: Bearer $1"})
}
hdr() { grep -i "^$1:" "$TMP/r.h" | head -1 | cut -d: -f2- | tr -d '\r' | sed 's/^ *//'; }
sql "DELETE FROM rate_limits WHERE bucket LIKE 'code-redeem-%';"
read -r TOKEN_L UID_L <<<"$(register "ordertest-l-$STAMP@example.com")"
read -r TOKEN_M UID_M <<<"$(register "ordertest-m-$STAMP@example.com")"
sql "DELETE FROM rate_limits WHERE bucket IN ('order-create:$UID_L','order-create:$UID_M');"
paid_order "$TOKEN_L" "{\"bookId\":$RB,\"purchaseType\":\"digital\"}"; LD=$NID
get_json "$TOKEN_L" "/api/me/orders/$LD/access-code"
post_json "$TOKEN_L" "/api/me/redeem" "{\"code\":\"$(code_of "$G")\"}"
check "section 8: book 2 digital order paid and code redeemed" "$([ "$VCODE" = "200" ] && [ "$PCODE" = "200" ] && [ "$(sql "SELECT revoked_at IS NULL FROM book_access WHERE user_id=$UID_L AND book_id=$RB")" = "1" ] && echo true || echo false)"

get_json "$TOKEN_L" "/api/books/$RB/access"
PAGES=$(echo "$G" | grep -o '"pageCount":[0-9]*' | grep -o '[0-9]*$')
check "access: owner -> {owned:true,status:active,pageCount>0}" "$([ "$GCODE" = "200" ] && jh "$G" '^\{"owned":true,"status":"active","pageCount":[1-9][0-9]*\}$' && echo true || echo false)"
check "access: pageCount equals pdfinfo of the private PDF" "$([ "$PAGES" = "$(docker compose exec -T php pdfinfo storage/books/$RB/book.pdf 2>/dev/null | grep '^Pages:' | grep -o '[0-9]*')" ] && echo true || echo false)"
get_json "$TOKEN_M" "/api/books/$RB/access"; G1=$G; C1=$GCODE
get_json "" "/api/books/$RB/access"; C2=$GCODE
get_json "$ADMIN_TOKEN" "/api/books/$RB/access"
check "access: non-owner {owned:false,status:none}; no token 401; admin 403" "$([ "$C1" = "200" ] && [ "$G1" = '{"owned":false,"status":"none"}' ] && [ "$C2" = "401" ] && [ "$GCODE" = "403" ] && echo true || echo false)"

hdr_get "$TOKEN_L" "/api/books/$RB/read/pages/1"
check "owner page 1 -> 200 image/jpeg, body starts FF D8 FF" "$([ "$RCODE" = "200" ] && [ "$(hdr Content-Type)" = "image/jpeg" ] && [ "$(head -c 3 "$TMP/r.bin" | od -An -tx1 | tr -d ' \n')" = "ffd8ff" ] && echo true || echo false)"
check "page headers: Cache-Control private, no-store; nosniff" "$([ "$(hdr Cache-Control)" = "private, no-store" ] && [ "$(hdr X-Content-Type-Options)" = "nosniff" ] && echo true || echo false)"
hdr_get "$TOKEN_L" "/api/books/$RB/read/pages/$PAGES"
check "owner last page ($PAGES) -> 200 image/jpeg" "$([ "$RCODE" = "200" ] && [ "$(hdr Content-Type)" = "image/jpeg" ] && echo true || echo false)"
hdr_get "$TOKEN_M" "/api/books/$RB/read/pages/1"; C1=$RCODE; B1=$(cat "$TMP/r.bin")
hdr_get "" "/api/books/$RB/read/pages/1"; C2=$RCODE
hdr_get "$ADMIN_TOKEN" "/api/books/$RB/read/pages/1"
check "page: non-owner 403 forbidden, no token 401, admin 403" "$([ "$C1" = "403" ] && jh "$B1" '"code":"forbidden"' && [ "$C2" = "401" ] && [ "$RCODE" = "403" ] && echo true || echo false)"
CODES=""
for p in 0 $((PAGES + 1)) abc "1%3Bls" -1 99999999999; do hdr_get "$TOKEN_L" "/api/books/$RB/read/pages/$p"; CODES="$CODES $RCODE"; done
hdr_get "$TOKEN_L" "/api/books/2abc/read/pages/1"; CODES="$CODES $RCODE"
check "page 0, N+1, abc, 1;ls, -1, huge, bad book id -> 404" "$([ "$CODES" = " 404 404 404 404 404 404 404" ] && echo true || echo false)"

CODES=""
for p in /storage/books/$RB/book.pdf /storage/books/$RB/pages/1.jpg /uploads/books/$RB/book.pdf /uploads/books/$RB/pages/1.jpg /books/$RB/book.pdf /api/books/$RB/book.pdf /api/books/$RB/pdf "/uploads/books/$RB/../../../storage/books/$RB/book.pdf"; do
  hdr_get "" "$p"; CODES="$CODES $RCODE"
  head -c 4 "$TMP/r.bin" | grep -q '%PDF' && CODES="$CODES PDF"
  [ "$(head -c 3 "$TMP/r.bin" | od -An -tx1 | tr -d ' \n')" = "ffd8ff" ] && CODES="$CODES JPEG"
done
check "direct private paths -> 404, never PDF or page bytes" "$([ "$CODES" = " 404 404 404 404 404 404 404 404" ] && echo true || echo false)"
get_json "" "/api/books/$RB"
check "public book payload unchanged: no pageCount, previews/index stay public uploads, no reader pages" "$([ "$GCODE" = "200" ] && ! jh "$G" 'pageCount|storage|/read/pages|book\.pdf' && echo true || echo false)"

# Rate limit: 240 page calls per user per fixed 5-min window; avoid straddling the boundary.
INTO=$(( $(date +%s) % 300 )); [ "$INTO" -gt 240 ] && sleep $((301 - INTO))
sql "DELETE FROM rate_limits WHERE bucket='reader-page:$UID_L';"
ARGS=(); for i in $(seq 1 240); do ARGS+=(-o /dev/null "$BASE_URL/api/books/$RB/read/pages/1"); done
BURST=$(curl -s -w '%{http_code}\n' -H "Authorization: Bearer $TOKEN_L" "${ARGS[@]}" | sort | uniq -c | tr -s ' ' | sed 's/^ //')
hdr_get "$TOKEN_L" "/api/books/$RB/read/pages/1"; C1=$RCODE; B1=$(cat "$TMP/r.bin")
hdr_get "$TOKEN_M" "/api/books/$RB/read/pages/1"
check "240 page calls -> 200, 241st -> 429 rate_limited; other user (same IP) unaffected" "$([ "$BURST" = "240 200" ] && [ "$C1" = "429" ] && jh "$B1" '"code":"rate_limited"' && [ "$RCODE" = "403" ] && echo true || echo false)"
sql "DELETE FROM rate_limits WHERE bucket='reader-page:$UID_L';"

# Revoked: refund the book 2 order (finalised by admin approve) -> reader 403
post_json "$TOKEN_L" "/api/me/orders/$LD/refund-request" '{"reason":"reader test"}'; RFL=$(id_of "$P")
post_json "$ADMIN_TOKEN" "/api/admin/refunds/$RFL/approve" '{}'
get_json "$TOKEN_L" "/api/books/$RB/access"
hdr_get "$TOKEN_L" "/api/books/$RB/read/pages/1"
check "after refund: access {owned:false,status:revoked}, page 403 forbidden" "$(jh "$P" '"status":"refunded"' && [ "$G" = '{"owned":false,"status":"revoked"}' ] && [ "$RCODE" = "403" ] && grep -q '"code":"forbidden"' "$TMP/r.bin" && echo true || echo false)"

echo ""
echo "Results: $PASS passed, $FAIL failed"
[ "$FAIL" -gt 0 ] && exit 1
exit 0
