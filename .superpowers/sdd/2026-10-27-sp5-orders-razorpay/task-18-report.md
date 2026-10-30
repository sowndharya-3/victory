# Task 18 report: reader frontend

Done: `src/api/reader.js` (`fetchAccess`, `fetchPageBlobUrl` -> authenticated fetch -> blob URL, errors carry `status`),
`src/components/reader/ReaderViewer.jsx` (+7 tests), rewritten `src/pages/ReaderPage.jsx` (+3 tests, old mock-reader tests removed),
reader classes appended to `src/styles/site.css`, `API_BASE_URL` exported from `src/api/client.js`.
Tests: frontend 125 -> 131 (28 files), `npm run build` green.

Decisions:
- Email comes from `SessionContext.customerEmail` (set on login; there is no AuthContext). Watermark id = bookId (access API has no orderNumber; `orderNumber` prop is supported if a caller has it).
- Blob cache holds current + next page only; leaving that window or unmounting revokes. A cancelled in-flight fetch revokes its URL on arrival (so a fast Next can re-fetch the prefetched page once).
- Jump input has no min/max so native validation doesn't block the clamp. Arrow keys are ignored while an input is focused.
- `fetchBook` failure is non-fatal (owners can read unpublished books); title/index just disappear. Owned with no `pageCount` -> "not available to read yet".
- Revoked access (owned=false, status=revoked) shows the no-access screen with "(access was revoked)"; a 403 mid-read shows "Access revoked".

Concerns:
- `src/data/books.js` seed still has `pageImages` (SessionContext seed data with its own test); no reader code uses it any more.
- `indexUrl` is used as returned by the API (relative `/uploads/...`), same as `coverUrl` elsewhere; cross-origin dev setups may need the API base prefixed.
- Not checked in a real browser (no Docker/backend per instructions).
