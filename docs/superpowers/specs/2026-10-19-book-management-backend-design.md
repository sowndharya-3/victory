# Book Management Backend (Sub-project 4)

## Context

Sub-project 2 (backend foundation) is merged: PHP + MySQL + Docker + JWT auth
(customer and admin), the full 11-table platform schema (including `books`
with `cover_url` and `digital_file_path` columns already anticipating this
work), and `AuthMiddleware::requireRole()` for admin-only routes. Nothing
reads or writes `books` yet — the public `BooksPage` and the admin book
screens are still 100% mock `SessionContext` state (confirmed by direct
inspection, not assumed).

This sub-project builds real book CRUD + file upload APIs and connects the
**public** book listing page to them. The spec covers index-preview assets
and the complete-PDF upload/storage as explicitly requested, but — per
requirement #9 and prior sub-project boundaries — does not build payment,
entitlement, or the authenticated reader-download endpoint; the complete
PDF is stored privately and simply isn't servable by anything yet, which is
correct for this phase (nothing should be able to read it until a purchase
exists).

## Scope decision surfaced for review

The user's requirement #6 says: "Connect the existing React **book listing
UI** to the book listing API without changing its current design." That's
the public `BooksPage` (just redesigned in sub-project 3). It does **not**
mention the admin book-management screens (`AdminBooksPage`,
`AdminLoginPage`). This spec wires only the public listing/detail pages to
the real backend; the admin UI stays on mock `SessionContext` state for
now — admins can fully exercise the new backend via the APIs directly
(verified with real HTTP requests in this sub-project's tests), but the
`/admin` React screens aren't rewired to it yet. **If this is wrong, say so
before implementation starts** — wiring `AdminBooksPage` to the real
backend too is a bounded addition to this same plan, not a new sub-project,
if you want it included now.

## Goals

- Real `books` CRUD, admin-authenticated, against live MySQL.
- Cover image, index document, and 1–2 preview-page images: uploaded,
  validated, stored, and publicly viewable before purchase.
- Complete book PDF: uploaded, validated, stored **privately** — never
  served by any endpoint in this sub-project.
- Public `GET /api/books` (list) and `GET /api/books/:id` (detail) —
  published-and-not-archived only, no admin fields leaked.
- Public `BooksPage` reads from this API instead of mock `SessionContext`
  books, with its current design/markup unchanged.
- Everything verified against the actual running Docker stack, same rigor
  as sub-project 2 (real HTTP requests, real file uploads, real MySQL rows
  — not inferred from reading the code).

## Non-goals (future sub-projects)

Razorpay, orders, purchase entitlements, the authenticated reader endpoint
that will eventually serve the private PDF, hard-copy addresses, referral
commissions. Wiring `AdminBooksPage`/`AdminLoginPage` to the real backend
(see Scope decision above — only if the user asks for it).

## Database changes

`books` already has `cover_url`, `digital_file_path`, `published`. Adding:

```sql
ALTER TABLE books
  ADD COLUMN index_file_path VARCHAR(500) NULL
    COMMENT 'publicly viewable index/table-of-contents document',
  ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE book_preview_pages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  book_id INT NOT NULL,
  page_number INT NOT NULL,
  file_path VARCHAR(500) NOT NULL COMMENT 'publicly viewable preview image',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_book_page (book_id, page_number),
  FOREIGN KEY (book_id) REFERENCES books(id)
);
```

A table (not fixed `preview_page_1`/`preview_page_2` columns) for preview
pages keeps the "1–2 pages now, 3 later" requirement a data change, not a
schema change (requirement #10).

**Status model:** `published` (bool) + `archived` (bool), not a new enum —
covers Draft (`published=0, archived=0`), Published (`published=1,
archived=0`), Unpublished (`published=0, archived=0` — same state as
draft, which matches the original site's own "unpublish just takes it back
off the public list" behavior), Archived (`archived=1`, hidden everywhere
regardless of `published`, preserved for any future order references).

## Storage layout

```
backend/
├── public/
│   └── uploads/
│       └── books/{id}/
│           ├── cover.<ext>
│           ├── index.<ext>
│           └── preview-{n}.<ext>
└── storage/
    └── books/{id}/
        └── book.pdf          # outside public/, nginx cannot serve it
```

Cover, index, and preview images are legitimately public (customers must
see them before buying) — stored under `public/uploads/`, served directly
by nginx as static files, URL returned as `/uploads/books/{id}/cover.jpg`
etc. The complete PDF goes under `backend/storage/` (sibling to `public/`,
outside nginx's docroot) — no route serves it in this sub-project, so it's
unreachable by design, not just by convention.

**Filenames are never user-supplied.** Uploaded files are renamed to a
fixed pattern (`cover.<detected-ext>`, `preview-{n}.<detected-ext>`,
`book.pdf`) based on the detected MIME type, not the client's original
filename — removes path-traversal and extension-spoofing surface entirely.

## Validation

- **Images** (cover, index-if-image, preview pages): MIME sniffed via
  `finfo_file` (not trusted from the client's `Content-Type` header or
  filename extension) must be `image/jpeg`, `image/png`, or `image/webp`.
  Max 5 MB.
- **Index**, if uploaded as a document instead of an image: also accepts
  `application/pdf`, same 5 MB cap.
- **Complete book PDF:** MIME sniffed must be `application/pdf`. Max 50 MB.
- Any validation failure: `400` with `{"error": {"code": "invalid_file", "message": "..."}}`,
  naming the specific problem (wrong type / too large).

## Endpoints

All admin endpoints require `Authorization: Bearer <admin-JWT>` via the
existing `AuthMiddleware::requireRole(['admin'])`.

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/api/books` | none | List published, non-archived books. `{id, title, description, category, coverUrl, onlinePrice, hardcopyPrice}[]` |
| GET | `/api/books/:id` | none | One published, non-archived book, plus `indexUrl`, `previewPages: [{pageNumber, url}]`. 404 if missing/unpublished/archived |
| GET | `/api/admin/books` | admin | List every book, any status, with upload-status flags (`hasCover`, `hasIndex`, `hasPdf`, `previewPageCount`) |
| GET | `/api/admin/books/:id` | admin | Full detail, any status |
| POST | `/api/admin/books` | admin | `{title, description, category, onlinePrice, hardcopyPrice}` → 201, `published=0, archived=0` |
| PUT | `/api/admin/books/:id` | admin | Update any metadata field (partial) |
| POST | `/api/admin/books/:id/publish` | admin | Sets `published=1` |
| POST | `/api/admin/books/:id/unpublish` | admin | Sets `published=0` |
| POST | `/api/admin/books/:id/archive` | admin | Sets `archived=1` |
| POST | `/api/admin/books/:id/cover` | admin | multipart `file` → validates, stores, updates `cover_url` |
| POST | `/api/admin/books/:id/index` | admin | multipart `file` (image or PDF) → stores, updates `index_file_path` |
| POST | `/api/admin/books/:id/preview-pages/:pageNumber` | admin | multipart `file` (image) → upserts that page |
| DELETE | `/api/admin/books/:id/preview-pages/:pageNumber` | admin | removes that page's row + file |
| POST | `/api/admin/books/:id/pdf` | admin | multipart `file` (PDF) → stores **privately**, updates `digital_file_path` |

Every admin mutation goes through a transaction where it touches more than
one table (e.g. deleting a book — not in this list, deliberately: per the
spec's own non-goal "do not permanently delete book records... without
considering existing order records," this sub-project has archive, not
hard delete).

## Frontend integration

`src/api/books.js` (new, mirrors the existing `src/api/auth.js` pattern):
`fetchBooks()` → `GET /api/books`, `fetchBook(id)` → `GET /api/books/:id`.

`BooksPage.jsx`: replace `useSession().state.books` with these real calls
(loading/error states added, since this is now a real network call — the
component's rendered markup/CSS is untouched, only its data source
changes). `BookDetailPage.jsx` is **not** touched in this sub-project
(it currently handles purchase/referral flow against mock orders, which
depends on sub-project 5's work — rewiring just its book-fetch half now
would leave it in a confusing mixed state. Deferred to sub-project 5,
which touches that page anyway for the real purchase flow).

## Error handling

Same conventions as sub-project 2: `ApiException` → `{"error": {"code", "message"}}`
with the right HTTP status. New codes: `invalid_file` (400), `not_found` (404,
reused), `validation_error` (400, reused).

## Testing

Extend the existing `backend/tests/` pattern — a new
`backend/tests/book_test.sh` (same style as `http_test.sh`) run against the
live Docker stack: admin create → appears in admin list, not in public
list (unpublished) → publish → appears in public list → cover upload (a
real small test JPEG) → index upload → preview-page upload (page 1, then
page 2) → PDF upload → public detail response includes `coverUrl`/
`indexUrl`/`previewPages` but **never** `digital_file_path` or anything
that reveals the PDF's storage path → archive → disappears from public
list even though still `published=1` → customer-role JWT on an admin
endpoint → 403 → no token → 401 → oversized/wrong-type file upload → 400.
Capture real output, same as sub-project 2's `TEST_RESULTS.md` pattern.

Frontend: `BooksPage.test.jsx` updated to mock `src/api/books.js` (same
pattern as `AccountPage.test.jsx` mocking `src/api/auth.js`) instead of
seeding `SessionContext` — must still prove published-only filtering,
now via the API response shape rather than local state.
