# Phase 1: React Frontend Conversion + Book Sales/Referral UI (mock data)

## Context

Victory Today Educational Enterprises runs a single static `index.html`
(inline CSS/JS, anchor-nav sections: Home, Founder, Services, Mission &
Vision, Contact). The org's publishing arm, Victory Today Publications,
needs a book sales + referral platform bolted onto this site. Full platform
spec (see project CLAUDE.md context) covers books, referrals, purchases,
Razorpay, a protected digital reader, and an admin panel, backed by a
PHP + MySQL API.

This is phase 1 of that build: convert the existing site to React and
build every new-feature UI against mock data, with no backend yet. Phase 2
(separate spec) adds the PHP + MySQL API and wires it in.

## Goals

- Existing site's design, copy, layout, responsiveness, animations reproduced
  exactly in React — no unrequested visual changes.
- All new book-sales/referral features click-through-able end to end using
  mock data, in a shape that mirrors the future API response so swapping in
  real endpoints later is a fetch-layer change, not a component rewrite.
- Multi-page routing (existing site is single-page anchor nav; new features
  need real routes).

## Non-goals (later phases)

Real authentication, real Razorpay integration, PHP API, MySQL, real file
storage/watermarking, real screenshot/recording deterrence, real referral
commission calculation persistence.

## Architecture

- Vite + React (JS) + React Router.
- App replaces the root `index.html` static site; existing site becomes the
  `/` route (`HomePage`), split into components: `Hero`, `WhySection`,
  `Founder`, `Services`, `MissionVision`, `Contact`. Inline `<style>` block
  extracted to a stylesheet, styles unchanged. Inline `<script>` behavior
  (mobile menu toggle, enquiry form, back-to-top, fade-in on scroll)
  reproduced as component logic.
- Nav gains two links: "Books", "Become a Referral Partner".
- Mock data layer under `src/data/`: `books.js`, `referralPartners.js`,
  `orders.js` (seed arrays shaped like future API JSON).
- Mock session/state via React Context: current customer (mock login),
  current admin (mock login), in-memory purchases/orders/registrations
  created during the session (not persisted beyond reload — localStorage
  optional convenience, not a requirement).

## Routes / Pages

- `/` — existing site (unchanged look)
- `/books` — book listing (cover, title, type, prices)
- `/books/:id` — book detail (description, Purchase / Read Book button —
  Read only if mock-purchased)
- `/checkout/:bookId` — digital vs hard copy choice, referral-partner
  dropdown (active mock partners), mock Razorpay button (stub — marks mock
  order "paid" without a real gateway call)
- `/referral/register` — registration form (all 9 required fields), adds to
  mock partner list as "pending"
- `/read/:bookId` — digital reader: page list over mock page images,
  next/prev + swipe, zoom, right-click disabled, text selection disabled on
  the page canvas, gated on mock purchase state, shows a small
  user/order-id watermark overlay
- `/account` — mock customer dashboard: purchased books list
- `/admin` — mock hardcoded login gate
- `/admin/dashboard` — mock stats (counts, revenue, commission summary)
- `/admin/books` — book CRUD (create/edit/publish/unpublish/delete, all
  against mock array)
- `/admin/customers` — mock customer/order list
- `/admin/referrals` — partner list, approve/reject, activate/deactivate,
  referral code display
- `/admin/orders` — order list with filters (book, digital/hard copy,
  referral partner)
- `/admin/commissions` — commission per partner/order (₹25 digital, ₹25
  hard copy, configurable value in mock admin settings)

## Error handling

Mock layer only — no network errors to handle. Form validation (referral
registration, checkout) is client-side required-field validation only.

## Testing

Manual: run dev server, confirm existing homepage renders identically to
current site, then click through every new flow (browse → detail → refer →
checkout → mock-pay → read → account; admin: login → manage books → approve
referral → view order → view commission) with mock data.
