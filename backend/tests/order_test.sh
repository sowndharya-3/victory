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
    sql "DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE book_id=$BOOK_ID);
         DELETE FROM hardcopy_addresses WHERE order_id IN (SELECT id FROM orders WHERE book_id=$BOOK_ID);
         DELETE FROM book_access WHERE book_id=$BOOK_ID;
         DELETE FROM orders WHERE book_id=$BOOK_ID;
         DELETE FROM book_preview_pages WHERE book_id=$BOOK_ID; DELETE FROM books WHERE id=$BOOK_ID;"
  fi
  sql "DELETE FROM rate_limits WHERE bucket LIKE 'order-create:%' AND SUBSTRING_INDEX(bucket,':',-1) IN (SELECT id FROM users WHERE email LIKE 'ordertest-%@example.com');
       DELETE FROM users WHERE email LIKE 'ordertest-%@example.com';"
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
RZP_SECRET=$(grep '^RAZORPAY_KEY_SECRET=' "$(dirname "$0")/../.env" | cut -d= -f2- | tr -d '\r')
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

echo ""
echo "Results: $PASS passed, $FAIL failed"
[ "$FAIL" -gt 0 ] && exit 1
exit 0
