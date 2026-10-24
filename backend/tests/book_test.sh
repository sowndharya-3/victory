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

echo "=== Setup: admin token, test files ==="
ADMIN_TOKEN=$(curl -s -X POST "$BASE_URL/api/admin/login" -H "Content-Type: application/json" -d '{"username":"admin","password":"adminpass123"}' | grep -o '"token":"[^"]*"' | cut -d'"' -f4)
check "admin token obtained" "$([ -n "$ADMIN_TOKEN" ] && echo true || echo false)"

CUSTOMER_EMAIL="booktest-$(date +%s)@example.com"
CUSTOMER_TOKEN=$(curl -s -X POST "$BASE_URL/api/auth/register" -H "Content-Type: application/json" -d "{\"name\":\"T\",\"email\":\"$CUSTOMER_EMAIL\",\"password\":\"password123\"}" | grep -o '"token":"[^"]*"' | cut -d'"' -f4)
check "customer token obtained" "$([ -n "$CUSTOMER_TOKEN" ] && echo true || echo false)"

# ponytail: GD is not installed in the php container (confirmed via `php -m`),
# so imagejpeg() from the brief isn't available. Write the same minimal valid
# JPEG bytes (SOI + APP0/JFIF + EOI) that backend/tests/fixtures/test.jpg
# already uses — genuinely sniffs as image/jpeg via finfo, same as Task 2.
docker compose exec -T php php -r "file_put_contents('/tmp/book_test_cover.jpg', chr(0xFF).chr(0xD8).chr(0xFF).chr(0xE0).chr(0x00).chr(0x10).chr(0x4A).chr(0x46).chr(0x49).chr(0x46).chr(0x00).chr(0x01).chr(0x01).chr(0x00).chr(0x00).chr(0x01).chr(0x00).chr(0x01).chr(0x00).chr(0x00).chr(0xFF).chr(0xD9));" >/dev/null 2>&1
docker compose exec -T php php -r "file_put_contents('/tmp/book_test.pdf', \"%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\");" >/dev/null 2>&1
# ponytail: docker compose cp wants a service name, not a container id —
# "php" (the service) copies fine; `$(docker compose ps -q php)` does not.
docker compose cp php:/tmp/book_test_cover.jpg /tmp/book_test_cover.jpg >/dev/null 2>&1
docker compose cp php:/tmp/book_test.pdf /tmp/book_test.pdf >/dev/null 2>&1

echo "=== 1. Create a book ==="
CREATE=$(curl -s -o /tmp/book_create.json -w "%{http_code}" -X POST "$BASE_URL/api/admin/books" -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d '{"title":"Integration Test Book","onlinePrice":199,"hardcopyPrice":349}')
check "create returns 201" "$([ "$CREATE" = "201" ] && echo true || echo false)"
BOOK_ID=$(grep -o '"id":[0-9]*' /tmp/book_create.json | head -1 | grep -o '[0-9]*')
check "create returns an id" "$([ -n "$BOOK_ID" ] && echo true || echo false)"

echo "=== 2. New book is not public yet ==="
PUBLIC_LIST=$(curl -s "$BASE_URL/api/books")
check "unpublished book absent from public list" "$(echo "$PUBLIC_LIST" | grep -q "\"id\":$BOOK_ID" && echo false || echo true)"

echo "=== 3. Publish, then it's public ==="
curl -s -X POST "$BASE_URL/api/admin/books/$BOOK_ID/publish" -H "Authorization: Bearer $ADMIN_TOKEN" >/dev/null
PUBLIC_LIST=$(curl -s "$BASE_URL/api/books")
check "published book appears in public list" "$(echo "$PUBLIC_LIST" | grep -q "\"id\":$BOOK_ID" && echo true || echo false)"

echo "=== 4. Cover, index, preview-page, PDF uploads ==="
COVER=$(curl -s -X POST "$BASE_URL/api/admin/books/$BOOK_ID/cover" -H "Authorization: Bearer $ADMIN_TOKEN" -F "file=@/tmp/book_test_cover.jpg")
check "cover upload returns a coverUrl" "$(echo "$COVER" | grep -q '"coverUrl"' && echo true || echo false)"

INDEX=$(curl -s -X POST "$BASE_URL/api/admin/books/$BOOK_ID/index" -H "Authorization: Bearer $ADMIN_TOKEN" -F "file=@/tmp/book_test_cover.jpg")
check "index upload returns an indexUrl" "$(echo "$INDEX" | grep -q '"indexUrl"' && echo true || echo false)"

curl -s -X POST "$BASE_URL/api/admin/books/$BOOK_ID/preview-pages/1" -H "Authorization: Bearer $ADMIN_TOKEN" -F "file=@/tmp/book_test_cover.jpg" >/dev/null
PREVIEW2=$(curl -s -X POST "$BASE_URL/api/admin/books/$BOOK_ID/preview-pages/2" -H "Authorization: Bearer $ADMIN_TOKEN" -F "file=@/tmp/book_test_cover.jpg")
check "second preview page upload succeeds" "$(echo "$PREVIEW2" | grep -q '"pageNumber":2' && echo true || echo false)"

PDF=$(curl -s -X POST "$BASE_URL/api/admin/books/$BOOK_ID/pdf" -H "Authorization: Bearer $ADMIN_TOKEN" -F "file=@/tmp/book_test.pdf")
check "PDF upload returns pdfUploaded true" "$(echo "$PDF" | grep -q '"pdfUploaded":true' && echo true || echo false)"

echo "=== 5. Public detail shows assets, never the private PDF path ==="
DETAIL=$(curl -s "$BASE_URL/api/books/$BOOK_ID")
check "public detail includes coverUrl" "$(echo "$DETAIL" | grep -q '"coverUrl"' && echo true || echo false)"
check "public detail includes indexUrl" "$(echo "$DETAIL" | grep -q '"indexUrl"' && echo true || echo false)"
check "public detail includes 2 previewPages" "$(echo "$DETAIL" | grep -o '"pageNumber"' | wc -l | grep -q '^2$' && echo true || echo false)"
check "public detail never mentions digital_file_path or storage path" "$(echo "$DETAIL" | grep -q 'digital_file_path\|/storage/' && echo false || echo true)"
PUBLIC_LIST_BODY=$(curl -s "$BASE_URL/api/books")
check "public list never mentions digital_file_path" "$(echo "$PUBLIC_LIST_BODY" | grep -q 'digital_file_path' && echo false || echo true)"

echo "=== 6. Private PDF genuinely unreachable ==="
PDF_DIRECT=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/storage/books/$BOOK_ID/book.pdf")
check "direct PDF URL guess returns 404 (storage/ not in docroot)" "$([ "$PDF_DIRECT" = "404" ] && echo true || echo false)"

echo "=== 7. Edit propagates ==="
curl -s -X PUT "$BASE_URL/api/admin/books/$BOOK_ID" -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d '{"title":"Edited Title"}' >/dev/null
EDITED_LIST=$(curl -s "$BASE_URL/api/books")
check "edited title appears in public list" "$(echo "$EDITED_LIST" | grep -q '"title":"Edited Title"' && echo true || echo false)"

echo "=== 8. Unpublish removes from public list ==="
curl -s -X POST "$BASE_URL/api/admin/books/$BOOK_ID/unpublish" -H "Authorization: Bearer $ADMIN_TOKEN" >/dev/null
AFTER_UNPUBLISH=$(curl -s "$BASE_URL/api/books")
check "unpublished book absent from public list" "$(echo "$AFTER_UNPUBLISH" | grep -q "\"id\":$BOOK_ID" && echo false || echo true)"

echo "=== 9. Archive hides even if republished ==="
curl -s -X POST "$BASE_URL/api/admin/books/$BOOK_ID/publish" -H "Authorization: Bearer $ADMIN_TOKEN" >/dev/null
curl -s -X POST "$BASE_URL/api/admin/books/$BOOK_ID/archive" -H "Authorization: Bearer $ADMIN_TOKEN" >/dev/null
AFTER_ARCHIVE=$(curl -s "$BASE_URL/api/books")
check "archived book absent from public list even though published=1 was set first" "$(echo "$AFTER_ARCHIVE" | grep -q "\"id\":$BOOK_ID" && echo false || echo true)"

echo "=== 10. Role separation on admin endpoints ==="
NO_TOKEN_CREATE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/api/admin/books" -H "Content-Type: application/json" -d '{"title":"x","onlinePrice":1,"hardcopyPrice":1}')
check "create with no token returns 401" "$([ "$NO_TOKEN_CREATE" = "401" ] && echo true || echo false)"

CUSTOMER_CREATE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/api/admin/books" -H "Authorization: Bearer $CUSTOMER_TOKEN" -H "Content-Type: application/json" -d '{"title":"x","onlinePrice":1,"hardcopyPrice":1}')
check "create with customer-role token returns 403" "$([ "$CUSTOMER_CREATE" = "403" ] && echo true || echo false)"

CUSTOMER_UPLOAD=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/api/admin/books/$BOOK_ID/cover" -H "Authorization: Bearer $CUSTOMER_TOKEN" -F "file=@/tmp/book_test_cover.jpg")
check "cover upload with customer-role token returns 403" "$([ "$CUSTOMER_UPLOAD" = "403" ] && echo true || echo false)"

echo "=== 11. Invalid file rejected ==="
echo "not an image" > /tmp/book_test_bad.txt
BAD_FILE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/api/admin/books/$BOOK_ID/cover" -H "Authorization: Bearer $ADMIN_TOKEN" -F "file=@/tmp/book_test_bad.txt")
check "non-image file upload to cover endpoint returns 400" "$([ "$BAD_FILE" = "400" ] && echo true || echo false)"

echo "=== 12. Oversized file rejected (exceeds FileValidator's 5MB image cap, well within PHP's 50M ini limit) ==="
# ponytail: a real ~6.5MB JPEG — same minimal-valid-JPEG bytes as the cover
# fixture above, padded past FileValidator::IMAGE_MAX_BYTES but nowhere near
# the raised php.ini ceiling, so this exercises FileValidator's own cap
# rather than PHP's ini limit (which this fix wave raised to 50M precisely
# so this scenario would be reachable).
docker compose exec -T php php -r "file_put_contents('/tmp/book_test_oversized.jpg', chr(0xFF).chr(0xD8).chr(0xFF).chr(0xE0).chr(0x00).chr(0x10).chr(0x4A).chr(0x46).chr(0x49).chr(0x46).chr(0x00).chr(0x01).chr(0x01).chr(0x00).chr(0x00).chr(0x01).chr(0x00).chr(0x01).chr(0x00).chr(0x00) . str_repeat(chr(0x00), 6*1024*1024) . chr(0xFF).chr(0xD9));" >/dev/null 2>&1
docker compose cp php:/tmp/book_test_oversized.jpg /tmp/book_test_oversized.jpg >/dev/null 2>&1
OVERSIZED=$(curl -s -o /tmp/book_test_oversized.json -w "%{http_code}" -X POST "$BASE_URL/api/admin/books/$BOOK_ID/cover" -H "Authorization: Bearer $ADMIN_TOKEN" -F "file=@/tmp/book_test_oversized.jpg")
check "oversized (~6.5MB) image upload to cover endpoint returns 400" "$([ "$OVERSIZED" = "400" ] && echo true || echo false)"
check "oversized image upload returns invalid_file code" "$(grep -q '"code":"invalid_file"' /tmp/book_test_oversized.json && echo true || echo false)"

echo ""
echo "Results: $PASS passed, $FAIL failed"
if [ "$FAIL" -gt 0 ]; then
  exit 1
fi
