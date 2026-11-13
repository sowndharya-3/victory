# Task 17 report: secure reader backend

Done: migration 005 (`books.page_count`, applied to dev DB in place), `poppler-utils` in the php image, `Reader\PdfPageRenderer`
(pdfinfo/pdftoppm via argument-array proc_open, 30 s timeout, per-page flock, temp + rename, `render_failed` with no paths/stderr),
`ReaderController` (`GET /api/books/{id}/access`, `GET /api/books/{id}/read/pages/{n}`), `AdminBookController::uploadPdf` invalidates the cache,
nginx 404 for `/uploads/books/*/book.pdf|pages/` (defence in depth; storage is outside the web root). Tests: reader_test.php 33/33, order_test.sh 190/190, all gates green.

Decisions:
- Owners keep reading after a book is unpublished/archived (they paid); admins get no pass (customer role only).
- Rate limit `reader-page:{userId}` 240/5 min, counted before the access check (throttles probing too).
- Non-digit ids/pages and out-of-range pages -> 404 `not_found`; no/revoked access -> 403 even for nonexistent books (no enumeration).
- New fixture `tests/fixtures/reader.pdf` (3 pages, 1.1 KB); the existing 62-byte `test.pdf` is not a real PDF and is used by book_test.

Concerns:
- bullseye is EOL: Dockerfile now points the security repo at archive.debian.org. Production must use a supported base image.
- Docker daemon hung once mid-run; Docker Desktop was restarted (volumes intact). The interrupted order_test run cleaned itself up.
- Subprocess timeout path untested (hard to trigger); first render of a big page takes ~1 s, no pre-render on upload.
- Orphan dirs `public/uploads/books/{100,115}` and many archived "Edited Title" books predate this task (book_test leftovers).
