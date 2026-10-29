# Task 14 / 14b report

## Task 14 (b43623b) feat(sp5): order result polling and real account orders
- `OrderResultPage` (route `/orders/:orderId/result`): polls `fetchMyOrder` every 3 s until status != pending or 60 s; paid / failed+cancelled / timeout / fetch-error / no-token states; never grants access.
- `AccountPage` logged-in view now lists `fetchMyOrders(token)` (title, type, order no, status, fulfilment label for paid hard copy, total, date), "No orders yet.", error text. Old mock My Books list and `SeedOrdersAndLogin` test removed. Mock reducer untouched.
- `src/utils/orderLabels.js`: fulfilment labels, money, shortDate (shared).

## Task 14b
- `src/api/orders.js`: fetchLibrary, redeemCode, fetchAccessCode, reissueCode, trackOrder (+ tests).
- `MyBooksPage` (`/my-books`): books (Read Book only when active), redeem form, To redeem (Show my code -> Redeem), hard-copy orders with tracking code + label; no token -> login link. Codes held in component state only.
- `TrackOrderPage` (`/track`): public lookup, no address rendered, generic not-found text.
- `OrderResultPage`: paid digital shows code (only when digitalCode.status==='active') + "Redeem to My Books"; redeemed -> note; hard copy shows tracking code + /track link.
- Header: existing link relabelled "Account", new "My Books" -> /my-books. AccountPage links to My Books and Track.

## Tests
Full suite `npx vitest run --pool=forks`: 27 files, 125 tests pass (Task 14 commit: 25 files, 102). `npm run build` passes.

## Notes
- Redeem/other errors rely on `apiRequest` throwing the server message (client.js does not expose error `code`/status), so messages are shown verbatim; Track maps only the exact "Tracking code not found" message to the friendlier generic text.
- No UI for customer reissue (API function only).
- One transient run showed many suites failing to load immediately after editing App.jsx; an immediate rerun passed (likely low-disk / cache flake).
