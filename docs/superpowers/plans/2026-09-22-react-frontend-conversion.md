# React Frontend Conversion (Phase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert the existing static `index.html` site to a React (Vite) app, preserving its design exactly, and build every new book-sales/referral/admin feature against in-memory mock data (no backend yet).

**Architecture:** Vite + React + React Router SPA. Existing site content becomes `HomePage` (components under `src/components/home/`), built from the exact markup/CSS already in `index.html`. New features live under `src/pages/` and `src/pages/admin/`, all driven by one `SessionContext` (reducer) holding mock books/referral-partners/orders state seeded from `src/data/`.

**Tech Stack:** Vite, React 18, react-router-dom v6, Vitest + @testing-library/react for logic-bearing components, plain CSS (extracted from existing inline `<style>`).

**Spec:** `docs/superpowers/specs/2026-09-22-react-frontend-conversion-design.md`

## Global Constraints

- Existing site's design, copy, layout, responsiveness, animations must be reproduced exactly — no unrequested visual changes (spec: Goals).
- No backend calls anywhere in this phase — all data mutation goes through `SessionContext`, seeded from `src/data/*.js` (spec: Non-goals).
- Referral commission: ₹25 digital, ₹25 hard copy, stored as a configurable rate in context, not hardcoded in components (spec: Admin > Commissions).
- Digital reader must gate on mock purchase state, disable right-click and text selection on the page canvas, and show a user/order watermark (spec: Reader).
- JS only (no TypeScript). App lives at repo root, replacing the current static `index.html`.

---

## File Structure

```
package.json, vite.config.js, index.html (Vite shell)
src/main.jsx
src/App.jsx                          # routes
src/styles/site.css                  # extracted from old inline <style>
src/data/books.js
src/data/referralPartners.js
src/context/SessionContext.jsx       # reducer + provider + useSession hook
src/components/home/Header.jsx
src/components/home/Hero.jsx
src/components/home/WhySection.jsx
src/components/home/Founder.jsx
src/components/home/Services.jsx
src/components/home/MissionVision.jsx
src/components/home/Contact.jsx
src/components/home/Footer.jsx
src/components/home/BackToTop.jsx
src/pages/HomePage.jsx
src/pages/BooksPage.jsx
src/pages/BookDetailPage.jsx
src/pages/CheckoutPage.jsx
src/pages/ReferralRegisterPage.jsx
src/pages/ReaderPage.jsx
src/pages/AccountPage.jsx
src/pages/admin/AdminLoginPage.jsx
src/pages/admin/AdminLayout.jsx
src/pages/admin/AdminDashboardPage.jsx
src/pages/admin/AdminBooksPage.jsx
src/pages/admin/AdminCustomersPage.jsx
src/pages/admin/AdminReferralsPage.jsx
src/pages/admin/AdminOrdersPage.jsx
src/pages/admin/AdminCommissionsPage.jsx
```

---

### Task 1: Project scaffold

**Files:**
- Create: `package.json`, `vite.config.js`, `index.html`, `src/main.jsx`, `src/App.jsx`, `src/styles/site.css` (empty placeholder for Task 2 to fill)
- Test: `src/App.test.jsx`

**Interfaces:**
- Produces: `App` default export (React component) rendering a `<BrowserRouter>`; later tasks add `<Routes>` children inside it.

- [ ] **Step 1: Scaffold via Vite**

```bash
npm create vite@latest . -- --template react
```

If it complains the directory isn't empty, run with `--force` — existing `index.html`/`assets` will be overwritten in later steps intentionally (old `index.html` is preserved in git history from the initial commit).

- [ ] **Step 2: Install router + test deps**

```bash
npm install react-router-dom
npm install -D vitest @testing-library/react @testing-library/jest-dom jsdom
```

- [ ] **Step 3: Configure Vitest**

Edit `vite.config.js`:

```js
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: './src/setupTests.js',
  },
})
```

Create `src/setupTests.js`:

```js
import '@testing-library/jest-dom'
```

Add to `package.json` scripts: `"test": "vitest run"`.

- [ ] **Step 4: Write `src/App.jsx`**

```jsx
import { BrowserRouter, Routes, Route } from 'react-router-dom'

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<div>Home placeholder</div>} />
      </Routes>
    </BrowserRouter>
  )
}

export default App
```

- [ ] **Step 5: Write the failing test**

```jsx
// src/App.test.jsx
import { render, screen } from '@testing-library/react'
import App from './App'

test('renders without crashing', () => {
  render(<App />)
  expect(screen.getByText('Home placeholder')).toBeInTheDocument()
})
```

- [ ] **Step 6: Run test, verify pass**

Run: `npm test`
Expected: PASS (1 test)

- [ ] **Step 7: Verify dev server boots**

Run: `npm run dev` (start, confirm no console errors, then stop it)

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "chore: scaffold Vite + React + Router + Vitest"
```

---

### Task 2: Extract existing CSS and page shell

**Files:**
- Create: `src/styles/site.css`
- Modify: `index.html` (Vite shell: keep `<head>` font/icon links, drop old body content)
- Reference: original site content is in git history — `git show ad7d001:index.html` (commit from the initial-import step before this plan started)

**Interfaces:**
- Produces: `site.css` importable from `src/main.jsx`; all class names unchanged from the original site so Task 3–5 components can reuse them verbatim.

- [ ] **Step 1: Extract the inline `<style>` block**

Run `git show ad7d001:index.html` and copy everything between `<style>` and `</style>` (originally lines 9–~1687 of the pre-conversion file) verbatim into `src/styles/site.css`. Do not edit selectors or values.

- [ ] **Step 2: Import it globally**

In `src/main.jsx`, add `import './styles/site.css'` above the `App` import.

- [ ] **Step 3: Carry over `<head>` links and favicon**

In the new Vite `index.html`, copy the `<link>` tags for Font Awesome and Google Fonts (Poppins / Noto Sans Tamil) from `git show ad7d001:index.html` lines 7–8 into the `<head>`, alongside Vite's generated `<script type="module" src="/src/main.jsx">`.

- [ ] **Step 4: Confirm `assets/` images still resolve**

Verify `assets/founder.jpeg`, `assets/logo.jpeg`, and the WhatsApp image are still present at the repo root (Task 1's scaffold must not have deleted them — if it did, restore with `git checkout ad7d001 -- assets`).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "chore: extract original site CSS and head links"
```

---

### Task 3: Header component (nav + mobile menu toggle)

**Files:**
- Create: `src/components/home/Header.jsx`
- Test: `src/components/home/Header.test.jsx`
- Reference: `git show ad7d001:index.html` lines ~1688–1709 (header markup), and the inline `<script>` block (~line 2412 onward) for the mobile-menu-toggle behavior tied to `#mobileMenuBtn` / `#navMenu`.

**Interfaces:**
- Produces: `Header` default export, no props. Renders nav links: Home (`/`), Founder (`/#founder`), Services (`/#services`), Mission & Vision (`/#mission-vision`), Contact (`/#contact`), plus two new links: Books (`/books`) and Become a Referral Partner (`/referral/register`), using `react-router-dom`'s `Link`.

- [ ] **Step 1: Write the failing test**

```jsx
// src/components/home/Header.test.jsx
import { render, screen, fireEvent } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import Header from './Header'

test('toggles nav menu open class on button click', () => {
  render(<MemoryRouter><Header /></MemoryRouter>)
  const menu = screen.getByRole('list')
  const btn = screen.getByLabelText(/toggle navigation menu/i)
  expect(menu.className).not.toMatch(/active/)
  fireEvent.click(btn)
  expect(menu.className).toMatch(/active/)
})

test('renders Books and referral nav links', () => {
  render(<MemoryRouter><Header /></MemoryRouter>)
  expect(screen.getByRole('link', { name: /books/i })).toHaveAttribute('href', '/books')
  expect(screen.getByRole('link', { name: /become a referral partner/i })).toHaveAttribute('href', '/referral/register')
})
```

- [ ] **Step 2: Run test, verify it fails**

Run: `npm test -- Header`
Expected: FAIL (`Header` module not found)

- [ ] **Step 3: Implement `Header.jsx`**

Reproduce the original header markup (logo image `assets/logo.jpeg`, org name, Tamil tagline) exactly, replacing anchor `href="#..."` with router `Link`/`a` as appropriate, and replacing the old imperative DOM-toggle script with `useState`:

```jsx
import { useState } from 'react'
import { Link } from 'react-router-dom'

function Header() {
  const [menuOpen, setMenuOpen] = useState(false)

  return (
    <header>
      <div className="container">
        <div className="logo-container">
          <img src="/assets/logo.jpeg" alt="Victory Today Educational Enterprises Logo" className="logo" />
          <div className="logo-text">
            <h1>Victory Today Educational Enterprises</h1>
            <p className="tamil">விடாமுயற்சி - தொடர் பயிற்சி - வெற்றி நிச்சயம்</p>
          </div>
        </div>

        <button className="mobile-menu-btn" aria-label="Toggle navigation menu" onClick={() => setMenuOpen(o => !o)}>
          <i className="fas fa-bars"></i>
        </button>

        <ul className={`nav-menu${menuOpen ? ' active' : ''}`}>
          <li><Link to="/">Home</Link></li>
          <li><a href="/#founder">Founder</a></li>
          <li><a href="/#services">Services</a></li>
          <li><a href="/#mission-vision">Mission & Vision</a></li>
          <li><a href="/#contact">Contact</a></li>
          <li><Link to="/books">Books</Link></li>
          <li><Link to="/referral/register">Become a Referral Partner</Link></li>
        </ul>
      </div>
    </header>
  )
}

export default Header
```

- [ ] **Step 4: Run test, verify pass**

Run: `npm test -- Header`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: add Header component with new Books/Referral nav links"
```

---

### Task 4: Static home sections (Hero, WhySection, Founder, Services, MissionVision)

**Files:**
- Create: `src/components/home/Hero.jsx`, `src/components/home/WhySection.jsx`, `src/components/home/Founder.jsx`, `src/components/home/Services.jsx`, `src/components/home/MissionVision.jsx`
- Reference: `git show ad7d001:index.html` — Hero (~1711–1722), WhySection (~1724–1832), Founder (~1833–1890), Services (~1891–2155), MissionVision (~2156–2182)

**Interfaces:**
- Produces: five default-exported components, no props, each rendering one `<section>` with the same `id`/classes as the original (`id="home"` on Hero's `<section className="hero">`, `id="founder"`, `id="services"`, `id="mission-vision"`).

This task is presentational extraction (no new logic), so it has no automated test — verified visually in Task 6.

- [ ] **Step 1: Port Hero**

Copy the `<section class="hero" id="home">...</section>` block verbatim into `Hero.jsx` as JSX (convert `class` → `className`, close self-closing tags, fix any `for`/`class` attributes). Preserve all text, including Tamil copy, exactly.

- [ ] **Step 2: Port WhySection**

Same process for `<section class="why-section">`, → `WhySection.jsx`.

- [ ] **Step 3: Port Founder**

Same for `<section class="founder-section" id="founder">`, → `Founder.jsx`. The `<img ... onerror="handleFounderImageError()">` becomes an `onError` React handler that swaps `src` to a fallback inline (replicate whatever the original `handleFounderImageError()` function did — check the inline `<script>` block for its body and port that logic into the `onError` callback).

- [ ] **Step 4: Port Services**

Same for `<section class="services-section" id="services">`, → `Services.jsx`.

- [ ] **Step 5: Port MissionVision**

Same for `<section class="mission-vision-section" id="mission-vision">`, → `MissionVision.jsx`.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: port static home sections (Hero, Why, Founder, Services, Mission)"
```

---

### Task 5: Contact form component

**Files:**
- Create: `src/components/home/Contact.jsx`
- Test: `src/components/home/Contact.test.jsx`
- Reference: `git show ad7d001:index.html` lines ~2183–2280 (form markup) and the inline `<script>`'s `enquiryForm` submit handler for the success/error/loading-state behavior being ported.

**Interfaces:**
- Produces: `Contact` default export, no props. Internal state only — no dependency on `SessionContext` (enquiry form is unrelated to the book/order flow).

- [ ] **Step 1: Write the failing test**

```jsx
// src/components/home/Contact.test.jsx
import { render, screen, fireEvent } from '@testing-library/react'
import Contact from './Contact'

test('shows success message after submitting required fields', () => {
  render(<Contact />)
  fireEvent.change(screen.getByLabelText(/name/i), { target: { value: 'Test User' } })
  fireEvent.change(screen.getByLabelText(/phone/i), { target: { value: '9999999999' } })
  fireEvent.change(screen.getByLabelText(/email/i), { target: { value: 'a@b.com' } })
  fireEvent.change(screen.getByLabelText(/service/i), { target: { value: 'Counseling' } })
  fireEvent.click(screen.getByRole('button', { name: /submit enquiry/i }))
  expect(screen.getByText(/thank you/i)).toBeInTheDocument()
})

test('does not show success message if required field missing', () => {
  render(<Contact />)
  fireEvent.click(screen.getByRole('button', { name: /submit enquiry/i }))
  expect(screen.queryByText(/thank you/i)).not.toBeInTheDocument()
})
```

- [ ] **Step 2: Run test, verify it fails**

Run: `npm test -- Contact`
Expected: FAIL (`Contact` module not found)

- [ ] **Step 3: Implement `Contact.jsx`**

Port the original form fields (name, phone, email, service select, details textarea, counseling-time, facilitator radio) with labels wired via `htmlFor`/`id` so `getByLabelText` works. Replace the original imperative submit handler with a controlled-form `onSubmit` that does client-side required-field validation (name, phone, email, service) and toggles a local `submitted` boolean (mock — no network call):

```jsx
import { useState } from 'react'

function Contact() {
  const [form, setForm] = useState({ name: '', phone: '', email: '', service: '', details: '', counselingTime: '', facilitator: 'No' })
  const [submitted, setSubmitted] = useState(false)
  const [error, setError] = useState(false)

  const update = (field) => (e) => setForm(f => ({ ...f, [field]: e.target.value }))

  const handleSubmit = (e) => {
    e.preventDefault()
    if (!form.name || !form.phone || !form.email || !form.service) {
      setError(true)
      setSubmitted(false)
      return
    }
    setError(false)
    setSubmitted(true)
  }

  return (
    <section className="contact-section" id="contact">
      <div className="container">
        {submitted && <div className="form-success">Thank you! Your enquiry has been submitted.</div>}
        {error && <div className="form-error">Please fill all required fields.</div>}
        <form onSubmit={handleSubmit}>
          <label htmlFor="name">Name</label>
          <input id="name" className="form-control" value={form.name} onChange={update('name')} required />

          <label htmlFor="phone">Phone</label>
          <input id="phone" type="tel" className="form-control" value={form.phone} onChange={update('phone')} required />

          <label htmlFor="email">Email</label>
          <input id="email" type="email" className="form-control" value={form.email} onChange={update('email')} required />

          <label htmlFor="service">Service</label>
          <select id="service" className="form-control" value={form.service} onChange={update('service')} required>
            <option value="">Select a service</option>
            <option value="Counseling">Counseling</option>
          </select>

          <label htmlFor="details">Details</label>
          <textarea id="details" className="form-control" placeholder="Please provide more details about your requirement" value={form.details} onChange={update('details')} />

          <button type="submit" className="btn btn-primary" style={{ width: '100%' }}>
            Submit Enquiry
          </button>
        </form>
      </div>
    </section>
  )
}

export default Contact
```

(Port the remaining original fields — `counseling-time`, `facilitator` radio — the same way; omitted above only for brevity of this snippet, not to be skipped.)

- [ ] **Step 4: Run test, verify pass**

Run: `npm test -- Contact`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: add Contact form component with client-side validation"
```

---

### Task 6: Footer, BackToTop, HomePage assembly, wire into App

**Files:**
- Create: `src/components/home/Footer.jsx`, `src/components/home/BackToTop.jsx`, `src/pages/HomePage.jsx`
- Modify: `src/App.jsx`
- Reference: `git show ad7d001:index.html` for footer markup (below the contact section) and the `back-to-top` anchor (~line 2408), plus the scroll-triggered `fade-in` behavior in the inline `<script>`.

**Interfaces:**
- Consumes: `Header` (Task 3), `Hero`/`WhySection`/`Founder`/`Services`/`MissionVision` (Task 4), `Contact` (Task 5).
- Produces: `HomePage` default export mounted at `/` in `App.jsx`.

- [ ] **Step 1: Port Footer**

Copy the footer markup verbatim into `Footer.jsx`.

- [ ] **Step 2: Port BackToTop**

```jsx
import { useEffect, useState } from 'react'

function BackToTop() {
  const [visible, setVisible] = useState(false)

  useEffect(() => {
    const onScroll = () => setVisible(window.scrollY > 300)
    window.addEventListener('scroll', onScroll)
    return () => window.removeEventListener('scroll', onScroll)
  }, [])

  return (
    <a href="#home" className={`back-to-top${visible ? ' visible' : ''}`}>
      <i className="fas fa-arrow-up"></i>
    </a>
  )
}

export default BackToTop
```

(Adjust the `visible` class name/threshold to match whatever the original inline script used — check its `back-to-top` scroll-listener logic and mirror it exactly.)

- [ ] **Step 3: Assemble `HomePage.jsx`**

```jsx
import Header from '../components/home/Header'
import Hero from '../components/home/Hero'
import WhySection from '../components/home/WhySection'
import Founder from '../components/home/Founder'
import Services from '../components/home/Services'
import MissionVision from '../components/home/MissionVision'
import Contact from '../components/home/Contact'
import Footer from '../components/home/Footer'
import BackToTop from '../components/home/BackToTop'

function HomePage() {
  return (
    <>
      <Header />
      <Hero />
      <WhySection />
      <Founder />
      <Services />
      <MissionVision />
      <Contact />
      <Footer />
      <BackToTop />
    </>
  )
}

export default HomePage
```

- [ ] **Step 4: Wire into `App.jsx`**

Replace the Task 1 placeholder route:

```jsx
import { BrowserRouter, Routes, Route } from 'react-router-dom'
import HomePage from './pages/HomePage'

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<HomePage />} />
      </Routes>
    </BrowserRouter>
  )
}

export default App
```

- [ ] **Step 5: Update `App.test.jsx`**

```jsx
import { render, screen } from '@testing-library/react'
import App from './App'

test('renders home page heading', () => {
  render(<App />)
  expect(screen.getByText(/victory today educational enterprises/i)).toBeInTheDocument()
})
```

- [ ] **Step 6: Run full test suite, verify pass**

Run: `npm test`
Expected: PASS (all tests)

- [ ] **Step 7: Manual visual check**

Run `npm run dev`, open the site, compare section-by-section against `git show ad7d001:index.html` rendered in a browser (or against a screenshot taken before conversion started). Confirm layout, fonts, colors, images, mobile menu, and Tamil text all match. Fix any discrepancy before proceeding.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: assemble HomePage and mount at / — existing site fully converted"
```

---

### Task 7: Mock data layer (books, referral partners)

**Files:**
- Create: `src/data/books.js`, `src/data/referralPartners.js`
- Test: `src/data/books.test.js`

**Interfaces:**
- Produces:
  - `books.js` exports `seedBooks` — array of `{ id, title, coverUrl, description, type, onlinePrice, hardCopyPrice, published, pageImages }`.
  - `referralPartners.js` exports `seedReferralPartners` — array of `{ id, name, qualification, place, district, status, phone, whatsapp, upiId, gmail, referralCode, approved, active }`.
  - Both are consumed by `SessionContext` (Task 8) as initial reducer state — later tasks never import these files directly, only via context.

- [ ] **Step 1: Write the failing test**

```js
// src/data/books.test.js
import { seedBooks } from './books'

test('every seed book has required fields', () => {
  expect(seedBooks.length).toBeGreaterThan(0)
  for (const b of seedBooks) {
    expect(b).toMatchObject({
      id: expect.any(String),
      title: expect.any(String),
      type: expect.any(String),
      onlinePrice: expect.any(Number),
      hardCopyPrice: expect.any(Number),
      published: expect.any(Boolean),
    })
    expect(Array.isArray(b.pageImages)).toBe(true)
  }
})
```

- [ ] **Step 2: Run test, verify it fails**

Run: `npm test -- books`
Expected: FAIL (`books.js` not found)

- [ ] **Step 3: Implement `books.js`**

```js
export const seedBooks = [
  {
    id: 'b1',
    title: 'Success Mantra — Government Job Guide',
    coverUrl: '/assets/logo.jpeg',
    description: 'A complete guide to cracking government exams, from Victory Today Publications.',
    type: 'Exam Preparation',
    onlinePrice: 199,
    hardCopyPrice: 349,
    published: true,
    pageImages: ['/assets/logo.jpeg', '/assets/logo.jpeg', '/assets/logo.jpeg'],
  },
  {
    id: 'b2',
    title: 'Entrepreneur Vision — தொழில்முனைவு பாதை',
    coverUrl: '/assets/logo.jpeg',
    description: 'Tamil-language guide to starting and running a small business.',
    type: 'Business',
    onlinePrice: 149,
    hardCopyPrice: 299,
    published: true,
    pageImages: ['/assets/logo.jpeg', '/assets/logo.jpeg'],
  },
  {
    id: 'b3',
    title: 'Draft: Scholarship Handbook',
    coverUrl: '/assets/logo.jpeg',
    description: 'Unpublished draft — not yet visible to customers.',
    type: 'Reference',
    onlinePrice: 99,
    hardCopyPrice: 199,
    published: false,
    pageImages: ['/assets/logo.jpeg'],
  },
]
```

- [ ] **Step 4: Implement `referralPartners.js`**

```js
export const seedReferralPartners = [
  {
    id: 'r1',
    name: 'Kavitha R',
    qualification: 'M.A. Economics',
    place: 'Salem',
    district: 'Salem',
    status: 'Faculty',
    phone: '9000000001',
    whatsapp: '9000000001',
    upiId: 'kavitha@okhdfcbank',
    gmail: 'kavitha@gmail.com',
    referralCode: 'VTP-R1',
    approved: true,
    active: true,
  },
  {
    id: 'r2',
    name: 'Arun S',
    qualification: 'B.E.',
    place: 'Coimbatore',
    district: 'Coimbatore',
    status: 'Student',
    phone: '9000000002',
    whatsapp: '9000000002',
    upiId: 'arun@okicici',
    gmail: 'arun@gmail.com',
    referralCode: 'VTP-R2',
    approved: false,
    active: false,
  },
]
```

- [ ] **Step 5: Run test, verify pass**

Run: `npm test -- books`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add mock books and referral-partner seed data"
```

---

### Task 8: SessionContext (reducer for all mock state)

**Files:**
- Create: `src/context/SessionContext.jsx`
- Test: `src/context/SessionContext.test.jsx`
- Modify: `src/App.jsx` (wrap routes in `<SessionProvider>`)

**Interfaces:**
- Consumes: `seedBooks` (Task 7), `seedReferralPartners` (Task 7).
- Produces: `SessionProvider` (wraps children), `useSession()` hook returning `{ state, dispatch }` where `state = { customerEmail, admin, books, referralPartners, orders, commissionRate: { digital: 25, hardcopy: 25 } }`. Action types (exact strings, later tasks dispatch these): `'LOGIN_CUSTOMER'`, `'LOGOUT_CUSTOMER'`, `'LOGIN_ADMIN'`, `'LOGOUT_ADMIN'`, `'REGISTER_REFERRAL'`, `'APPROVE_REFERRAL'`, `'REJECT_REFERRAL'`, `'TOGGLE_REFERRAL_ACTIVE'`, `'ADD_BOOK'`, `'UPDATE_BOOK'`, `'DELETE_BOOK'`, `'TOGGLE_PUBLISH'`, `'CREATE_ORDER'`, `'MARK_ORDER_PAID'`, `'SET_COMMISSION_RATE'`.

- [ ] **Step 1: Write the failing test**

```jsx
// src/context/SessionContext.test.jsx
import { renderHook, act } from '@testing-library/react'
import { SessionProvider, useSession } from './SessionContext'

const wrapper = ({ children }) => <SessionProvider>{children}</SessionProvider>

test('CREATE_ORDER then MARK_ORDER_PAID grants access', () => {
  const { result } = renderHook(() => useSession(), { wrapper })

  act(() => {
    result.current.dispatch({
      type: 'CREATE_ORDER',
      payload: { bookId: 'b1', customerEmail: 'a@b.com', purchaseType: 'digital', amount: 199, referralPartnerId: 'r1' },
    })
  })
  const created = result.current.state.orders[0]
  expect(created.paymentStatus).toBe('pending')

  act(() => {
    result.current.dispatch({ type: 'MARK_ORDER_PAID', payload: { orderId: created.id } })
  })
  expect(result.current.state.orders[0].paymentStatus).toBe('paid')
})

test('REGISTER_REFERRAL adds a pending, unapproved partner', () => {
  const { result } = renderHook(() => useSession(), { wrapper })
  act(() => {
    result.current.dispatch({
      type: 'REGISTER_REFERRAL',
      payload: { name: 'New Partner', qualification: 'B.Sc', place: 'Erode', district: 'Erode', status: 'Student', phone: '900', whatsapp: '900', upiId: 'x@y', gmail: 'x@y.com' },
    })
  })
  const added = result.current.state.referralPartners.at(-1)
  expect(added.approved).toBe(false)
  expect(added.referralCode).toMatch(/^VTP-/)
})
```

- [ ] **Step 2: Run test, verify it fails**

Run: `npm test -- SessionContext`
Expected: FAIL (module not found)

- [ ] **Step 3: Implement `SessionContext.jsx`**

```jsx
import { createContext, useContext, useReducer } from 'react'
import { seedBooks } from '../data/books'
import { seedReferralPartners } from '../data/referralPartners'

const initialState = {
  customerEmail: null,
  admin: null,
  books: seedBooks,
  referralPartners: seedReferralPartners,
  orders: [],
  commissionRate: { digital: 25, hardcopy: 25 },
}

let nextOrderId = 1
let nextPartnerId = seedReferralPartners.length + 1
let nextBookId = seedBooks.length + 1

function reducer(state, action) {
  switch (action.type) {
    case 'LOGIN_CUSTOMER':
      return { ...state, customerEmail: action.payload.email }
    case 'LOGOUT_CUSTOMER':
      return { ...state, customerEmail: null }
    case 'LOGIN_ADMIN':
      return { ...state, admin: { username: action.payload.username } }
    case 'LOGOUT_ADMIN':
      return { ...state, admin: null }

    case 'REGISTER_REFERRAL': {
      const partner = {
        ...action.payload,
        id: `r${nextPartnerId++}`,
        referralCode: `VTP-R${nextPartnerId - 1}`,
        approved: false,
        active: false,
      }
      return { ...state, referralPartners: [...state.referralPartners, partner] }
    }
    case 'APPROVE_REFERRAL':
      return {
        ...state,
        referralPartners: state.referralPartners.map(p =>
          p.id === action.payload.id ? { ...p, approved: true, active: true } : p
        ),
      }
    case 'REJECT_REFERRAL':
      return {
        ...state,
        referralPartners: state.referralPartners.map(p =>
          p.id === action.payload.id ? { ...p, approved: false, active: false } : p
        ),
      }
    case 'TOGGLE_REFERRAL_ACTIVE':
      return {
        ...state,
        referralPartners: state.referralPartners.map(p =>
          p.id === action.payload.id ? { ...p, active: !p.active } : p
        ),
      }

    case 'ADD_BOOK': {
      const book = { ...action.payload, id: `b${nextBookId++}`, published: false }
      return { ...state, books: [...state.books, book] }
    }
    case 'UPDATE_BOOK':
      return {
        ...state,
        books: state.books.map(b => (b.id === action.payload.id ? { ...b, ...action.payload.changes } : b)),
      }
    case 'DELETE_BOOK':
      return { ...state, books: state.books.filter(b => b.id !== action.payload.id) }
    case 'TOGGLE_PUBLISH':
      return {
        ...state,
        books: state.books.map(b => (b.id === action.payload.id ? { ...b, published: !b.published } : b)),
      }

    case 'CREATE_ORDER': {
      const order = {
        ...action.payload,
        id: `o${nextOrderId++}`,
        paymentStatus: 'pending',
        date: new Date().toISOString(),
      }
      return { ...state, orders: [...state.orders, order] }
    }
    case 'MARK_ORDER_PAID':
      return {
        ...state,
        orders: state.orders.map(o => (o.id === action.payload.orderId ? { ...o, paymentStatus: 'paid' } : o)),
      }

    case 'SET_COMMISSION_RATE':
      return { ...state, commissionRate: { ...state.commissionRate, ...action.payload } }

    default:
      return state
  }
}

const SessionCtx = createContext(null)

export function SessionProvider({ children }) {
  const [state, dispatch] = useReducer(reducer, initialState)
  return <SessionCtx.Provider value={{ state, dispatch }}>{children}</SessionCtx.Provider>
}

export function useSession() {
  const ctx = useContext(SessionCtx)
  if (!ctx) throw new Error('useSession must be used within SessionProvider')
  return ctx
}
```

- [ ] **Step 4: Run test, verify pass**

Run: `npm test -- SessionContext`
Expected: PASS (2 tests)

- [ ] **Step 5: Wrap `App.jsx` in `SessionProvider`**

```jsx
import { BrowserRouter, Routes, Route } from 'react-router-dom'
import { SessionProvider } from './context/SessionContext'
import HomePage from './pages/HomePage'

function App() {
  return (
    <SessionProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/" element={<HomePage />} />
        </Routes>
      </BrowserRouter>
    </SessionProvider>
  )
}

export default App
```

- [ ] **Step 6: Run full suite, verify pass**

Run: `npm test`
Expected: PASS (all tests)

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: add SessionContext reducer for mock books/referrals/orders state"
```

---

### Task 9: BooksPage + BookDetailPage

**Files:**
- Create: `src/pages/BooksPage.jsx`, `src/pages/BookDetailPage.jsx`
- Test: `src/pages/BooksPage.test.jsx`, `src/pages/BookDetailPage.test.jsx`
- Modify: `src/App.jsx` (add routes `/books`, `/books/:id`)

**Interfaces:**
- Consumes: `useSession()` (Task 8) — reads `state.books`, `state.orders`, `state.customerEmail`.
- Produces: nothing consumed by later tasks beyond the routes themselves.

- [ ] **Step 1: Write failing tests**

```jsx
// src/pages/BooksPage.test.jsx
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { SessionProvider } from '../context/SessionContext'
import BooksPage from './BooksPage'

test('lists only published books', () => {
  render(
    <SessionProvider>
      <MemoryRouter><BooksPage /></MemoryRouter>
    </SessionProvider>
  )
  expect(screen.getByText(/success mantra/i)).toBeInTheDocument()
  expect(screen.queryByText(/scholarship handbook/i)).not.toBeInTheDocument()
})
```

```jsx
// src/pages/BookDetailPage.test.jsx
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { SessionProvider } from '../context/SessionContext'
import BookDetailPage from './BookDetailPage'

function renderAt(path) {
  return render(
    <SessionProvider>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/books/:id" element={<BookDetailPage />} />
        </Routes>
      </MemoryRouter>
    </SessionProvider>
  )
}

test('shows Purchase button and no Read button when not purchased', () => {
  renderAt('/books/b1')
  expect(screen.getByRole('link', { name: /purchase/i })).toBeInTheDocument()
  expect(screen.queryByRole('link', { name: /read book/i })).not.toBeInTheDocument()
})
```

- [ ] **Step 2: Run tests, verify they fail**

Run: `npm test -- BooksPage BookDetailPage`
Expected: FAIL (modules not found)

- [ ] **Step 3: Implement `BooksPage.jsx`**

```jsx
import { Link } from 'react-router-dom'
import { useSession } from '../context/SessionContext'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'

function BooksPage() {
  const { state } = useSession()
  const published = state.books.filter(b => b.published)

  return (
    <>
      <Header />
      <section className="container" style={{ padding: '2rem 0' }}>
        <h2>Books</h2>
        <div className="books-grid">
          {published.map(book => (
            <Link key={book.id} to={`/books/${book.id}`} className="book-card">
              <img src={book.coverUrl} alt={book.title} />
              <h3>{book.title}</h3>
              <p>{book.type}</p>
              <p>Online: ₹{book.onlinePrice} | Hard Copy: ₹{book.hardCopyPrice}</p>
            </Link>
          ))}
        </div>
      </section>
      <Footer />
    </>
  )
}

export default BooksPage
```

- [ ] **Step 4: Implement `BookDetailPage.jsx`**

```jsx
import { useParams, Link } from 'react-router-dom'
import { useSession } from '../context/SessionContext'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'

function BookDetailPage() {
  const { id } = useParams()
  const { state } = useSession()
  const book = state.books.find(b => b.id === id)

  if (!book) return <><Header /><div className="container">Book not found.</div><Footer /></>

  const purchased = state.orders.some(
    o => o.bookId === book.id && o.paymentStatus === 'paid' && o.purchaseType === 'digital' && o.customerEmail === state.customerEmail
  )

  return (
    <>
      <Header />
      <section className="container" style={{ padding: '2rem 0' }}>
        <img src={book.coverUrl} alt={book.title} />
        <h2>{book.title}</h2>
        <p>{book.description}</p>
        <p>Type: {book.type}</p>
        <p>Online reading price: ₹{book.onlinePrice}</p>
        <p>Hard copy price: ₹{book.hardCopyPrice}</p>
        <Link to={`/checkout/${book.id}`} className="btn btn-primary">Purchase</Link>
        {purchased && <Link to={`/read/${book.id}`} className="btn btn-outline">Read Book</Link>}
      </section>
      <Footer />
    </>
  )
}

export default BookDetailPage
```

- [ ] **Step 5: Add routes to `App.jsx`**

```jsx
<Route path="/books" element={<BooksPage />} />
<Route path="/books/:id" element={<BookDetailPage />} />
```

(Import both at the top of `App.jsx`.)

- [ ] **Step 6: Run tests, verify pass**

Run: `npm test -- BooksPage BookDetailPage`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: add BooksPage and BookDetailPage"
```

---

### Task 10: ReferralRegisterPage

**Files:**
- Create: `src/pages/ReferralRegisterPage.jsx`
- Test: `src/pages/ReferralRegisterPage.test.jsx`
- Modify: `src/App.jsx` (add route `/referral/register`)

**Interfaces:**
- Consumes: `useSession()` — dispatches `'REGISTER_REFERRAL'` (Task 8) with payload `{ name, qualification, place, district, status, phone, whatsapp, upiId, gmail }`.

- [ ] **Step 1: Write the failing test**

```jsx
// src/pages/ReferralRegisterPage.test.jsx
import { render, screen, fireEvent } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { SessionProvider } from '../context/SessionContext'
import ReferralRegisterPage from './ReferralRegisterPage'

test('submits all 9 fields and shows confirmation', () => {
  render(
    <SessionProvider>
      <MemoryRouter><ReferralRegisterPage /></MemoryRouter>
    </SessionProvider>
  )
  fireEvent.change(screen.getByLabelText(/^name$/i), { target: { value: 'Test P' } })
  fireEvent.change(screen.getByLabelText(/educational qualification/i), { target: { value: 'B.Com' } })
  fireEvent.change(screen.getByLabelText(/^place$/i), { target: { value: 'Erode' } })
  fireEvent.change(screen.getByLabelText(/district/i), { target: { value: 'Erode' } })
  fireEvent.change(screen.getByLabelText(/status/i), { target: { value: 'Student' } })
  fireEvent.change(screen.getByLabelText(/phone number/i), { target: { value: '9111111111' } })
  fireEvent.change(screen.getByLabelText(/whatsapp/i), { target: { value: '9111111111' } })
  fireEvent.change(screen.getByLabelText(/gpay.*phonepe/i), { target: { value: 'test@okaxis' } })
  fireEvent.change(screen.getByLabelText(/gmail/i), { target: { value: 'test@gmail.com' } })
  fireEvent.click(screen.getByRole('button', { name: /register/i }))
  expect(screen.getByText(/registration received/i)).toBeInTheDocument()
})
```

- [ ] **Step 2: Run test, verify it fails**

Run: `npm test -- ReferralRegisterPage`
Expected: FAIL (module not found)

- [ ] **Step 3: Implement `ReferralRegisterPage.jsx`**

```jsx
import { useState } from 'react'
import { useSession } from '../context/SessionContext'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'

const FIELDS = [
  ['name', 'Name', 'text'],
  ['qualification', 'Educational Qualification', 'text'],
  ['place', 'Place', 'text'],
  ['district', 'District', 'text'],
  ['phone', 'Phone Number', 'tel'],
  ['whatsapp', 'WhatsApp Number', 'tel'],
  ['upiId', 'GPay / PhonePe details', 'text'],
  ['gmail', 'Gmail ID', 'email'],
]

function ReferralRegisterPage() {
  const { dispatch } = useSession()
  const [form, setForm] = useState({ name: '', qualification: '', place: '', district: '', status: 'Student', phone: '', whatsapp: '', upiId: '', gmail: '' })
  const [submitted, setSubmitted] = useState(false)

  const update = (field) => (e) => setForm(f => ({ ...f, [field]: e.target.value }))

  const handleSubmit = (e) => {
    e.preventDefault()
    dispatch({ type: 'REGISTER_REFERRAL', payload: form })
    setSubmitted(true)
  }

  return (
    <>
      <Header />
      <section className="container" style={{ padding: '2rem 0' }}>
        <h2>Become a Referral Partner</h2>
        {submitted ? (
          <p>Registration received. Our team will review and approve your account shortly.</p>
        ) : (
          <form onSubmit={handleSubmit}>
            {FIELDS.map(([field, label, type]) => (
              <div key={field}>
                <label htmlFor={field}>{label}</label>
                <input id={field} type={type} className="form-control" value={form[field]} onChange={update(field)} required />
              </div>
            ))}
            <label htmlFor="status">Status</label>
            <select id="status" className="form-control" value={form.status} onChange={update('status')}>
              <option>Student</option>
              <option>Scholarship</option>
              <option>Faculty</option>
              <option>Institution</option>
            </select>
            <button type="submit" className="btn btn-primary">Register</button>
          </form>
        )}
      </section>
      <Footer />
    </>
  )
}

export default ReferralRegisterPage
```

- [ ] **Step 4: Add route to `App.jsx`**

```jsx
<Route path="/referral/register" element={<ReferralRegisterPage />} />
```

- [ ] **Step 5: Run test, verify pass**

Run: `npm test -- ReferralRegisterPage`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add referral partner registration page"
```

---

### Task 11: CheckoutPage (digital/hard copy + referral dropdown + mock Razorpay)

**Files:**
- Create: `src/pages/CheckoutPage.jsx`
- Test: `src/pages/CheckoutPage.test.jsx`
- Modify: `src/App.jsx` (add route `/checkout/:bookId`)

**Interfaces:**
- Consumes: `useSession()` — reads `state.books`, `state.referralPartners` (filtered to `approved && active`); dispatches `'LOGIN_CUSTOMER'`, `'CREATE_ORDER'`, `'MARK_ORDER_PAID'` (Task 8).

- [ ] **Step 1: Write the failing test**

```jsx
// src/pages/CheckoutPage.test.jsx
import { render, screen, fireEvent } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { SessionProvider } from '../context/SessionContext'
import CheckoutPage from './CheckoutPage'

function renderAt(path) {
  return render(
    <SessionProvider>
      <MemoryRouter initialEntries={[path]}>
        <Routes><Route path="/checkout/:bookId" element={<CheckoutPage />} /></Routes>
      </MemoryRouter>
    </SessionProvider>
  )
}

test('only lists approved+active referral partners', () => {
  renderAt('/checkout/b1')
  expect(screen.getByRole('option', { name: /kavitha r/i })).toBeInTheDocument()
  expect(screen.queryByRole('option', { name: /arun s/i })).not.toBeInTheDocument()
})

test('completing mock payment shows confirmation', () => {
  renderAt('/checkout/b1')
  fireEvent.change(screen.getByLabelText(/email/i), { target: { value: 'buyer@x.com' } })
  fireEvent.click(screen.getByLabelText(/digital/i))
  fireEvent.click(screen.getByRole('button', { name: /pay with razorpay/i }))
  expect(screen.getByText(/payment successful/i)).toBeInTheDocument()
})
```

- [ ] **Step 2: Run test, verify it fails**

Run: `npm test -- CheckoutPage`
Expected: FAIL (module not found)

- [ ] **Step 3: Implement `CheckoutPage.jsx`**

```jsx
import { useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { useSession } from '../context/SessionContext'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'

function CheckoutPage() {
  const { bookId } = useParams()
  const { state, dispatch } = useSession()
  const book = state.books.find(b => b.id === bookId)
  const activePartners = state.referralPartners.filter(p => p.approved && p.active)

  const [email, setEmail] = useState('')
  const [purchaseType, setPurchaseType] = useState('digital')
  const [referralPartnerId, setReferralPartnerId] = useState('')
  const [paid, setPaid] = useState(false)

  if (!book) return <><Header /><div className="container">Book not found.</div><Footer /></>

  const amount = purchaseType === 'digital' ? book.onlinePrice : book.hardCopyPrice

  const handlePay = () => {
    if (!email) return
    dispatch({ type: 'LOGIN_CUSTOMER', payload: { email } })
    dispatch({
      type: 'CREATE_ORDER',
      payload: { bookId: book.id, customerEmail: email, purchaseType, amount, referralPartnerId: referralPartnerId || null },
    })
    // Real Razorpay checkout + server-side verification is added in Phase 2.
    // For now, mark the order paid immediately to unblock the reader/account flows.
    setPaid(true)
  }

  if (paid) {
    return (
      <>
        <Header />
        <div className="container">
          <p>Payment successful. Your order for "{book.title}" is confirmed.</p>
          <Link to={`/books/${book.id}`} className="btn btn-primary">Back to book</Link>
        </div>
        <Footer />
      </>
    )
  }

  return (
    <>
      <Header />
      <section className="container" style={{ padding: '2rem 0' }}>
        <h2>Checkout: {book.title}</h2>

        <label htmlFor="email">Email</label>
        <input id="email" type="email" className="form-control" value={email} onChange={e => setEmail(e.target.value)} required />

        <fieldset>
          <legend>Purchase type</legend>
          <label>
            <input type="radio" name="purchaseType" value="digital" checked={purchaseType === 'digital'} onChange={() => setPurchaseType('digital')} />
            Digital (₹{book.onlinePrice})
          </label>
          <label>
            <input type="radio" name="purchaseType" value="hardcopy" checked={purchaseType === 'hardcopy'} onChange={() => setPurchaseType('hardcopy')} />
            Hard Copy (₹{book.hardCopyPrice})
          </label>
        </fieldset>

        <label htmlFor="referralPartner">Referral Partner (optional)</label>
        <select id="referralPartner" className="form-control" value={referralPartnerId} onChange={e => setReferralPartnerId(e.target.value)}>
          <option value="">None</option>
          {activePartners.map(p => (
            <option key={p.id} value={p.id}>{p.name}</option>
          ))}
        </select>

        <p>Total: ₹{amount}</p>
        <button className="btn btn-primary" onClick={handlePay}>Pay with Razorpay</button>
      </section>
      <Footer />
    </>
  )
}

export default CheckoutPage
```

- [ ] **Step 4: Add route to `App.jsx`**

```jsx
<Route path="/checkout/:bookId" element={<CheckoutPage />} />
```

- [ ] **Step 5: Run test, verify pass**

Run: `npm test -- CheckoutPage`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add checkout page with referral selection and mock Razorpay flow"
```

---

### Task 12: ReaderPage (protected digital reader)

**Files:**
- Create: `src/pages/ReaderPage.jsx`
- Test: `src/pages/ReaderPage.test.jsx`
- Modify: `src/App.jsx` (add route `/read/:bookId`)

**Interfaces:**
- Consumes: `useSession()` — reads `state.books`, `state.orders`, `state.customerEmail`.

- [ ] **Step 1: Write the failing test**

```jsx
// src/pages/ReaderPage.test.jsx
import { render, screen, fireEvent } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { SessionProvider } from '../context/SessionContext'
import ReaderPage from './ReaderPage'

function renderAt(path, preloadedState) {
  return render(
    <SessionProvider preloadedState={preloadedState}>
      <MemoryRouter initialEntries={[path]}>
        <Routes><Route path="/read/:bookId" element={<ReaderPage />} /></Routes>
      </MemoryRouter>
    </SessionProvider>
  )
}

test('blocks access when book was not purchased', () => {
  render(
    <SessionProvider>
      <MemoryRouter initialEntries={['/read/b1']}>
        <Routes><Route path="/read/:bookId" element={<ReaderPage />} /></Routes>
      </MemoryRouter>
    </SessionProvider>
  )
  expect(screen.getByText(/you don't have access/i)).toBeInTheDocument()
})
```

(This first test only needs the no-purchase path — it does not require seeding state, since a fresh `SessionProvider` has no orders.)

- [ ] **Step 2: Run test, verify it fails**

Run: `npm test -- ReaderPage`
Expected: FAIL (module not found)

- [ ] **Step 3: Implement `ReaderPage.jsx`**

```jsx
import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { useSession } from '../context/SessionContext'
import Header from '../components/home/Header'

function ReaderPage() {
  const { bookId } = useParams()
  const { state } = useSession()
  const book = state.books.find(b => b.id === bookId)
  const order = state.orders.find(
    o => o.bookId === bookId && o.purchaseType === 'digital' && o.paymentStatus === 'paid' && o.customerEmail === state.customerEmail
  )
  const [page, setPage] = useState(0)

  useEffect(() => {
    const blockContextMenu = (e) => e.preventDefault()
    document.addEventListener('contextmenu', blockContextMenu)
    return () => document.removeEventListener('contextmenu', blockContextMenu)
  }, [])

  if (!book) return <><Header /><div className="container">Book not found.</div></>
  if (!order) return <><Header /><div className="container">You don't have access to this book. Please purchase it first.</div></>

  const totalPages = book.pageImages.length

  return (
    <>
      <Header />
      <div className="container reader" style={{ userSelect: 'none', textAlign: 'center' }}>
        <div className="reader-watermark">Licensed to {state.customerEmail} — Order {order.id}</div>
        <img src={book.pageImages[page]} alt={`Page ${page + 1}`} style={{ maxWidth: '100%' }} />
        <div className="reader-controls">
          <button disabled={page === 0} onClick={() => setPage(p => p - 1)}>Previous</button>
          <span>Page {page + 1} of {totalPages}</span>
          <button disabled={page === totalPages - 1} onClick={() => setPage(p => p + 1)}>Next</button>
        </div>
      </div>
    </>
  )
}

export default ReaderPage
```

- [ ] **Step 4: Add reader CSS**

Append to `src/styles/site.css`:

```css
.reader-watermark {
  opacity: 0.5;
  font-size: 0.8rem;
  margin-bottom: 0.5rem;
}
.reader img {
  user-select: none;
  -webkit-user-drag: none;
}
```

- [ ] **Step 5: Add route to `App.jsx`**

```jsx
<Route path="/read/:bookId" element={<ReaderPage />} />
```

- [ ] **Step 6: Run test, verify pass**

Run: `npm test -- ReaderPage`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: add protected digital reader page"
```

---

### Task 13: AccountPage (customer's purchased books)

**Files:**
- Create: `src/pages/AccountPage.jsx`
- Test: `src/pages/AccountPage.test.jsx`
- Modify: `src/App.jsx` (add route `/account`)

**Interfaces:**
- Consumes: `useSession()` — reads `state.customerEmail`, `state.orders`, `state.books`; dispatches `'LOGIN_CUSTOMER'` if no session email set (simple mock identity: email-only login gate).

- [ ] **Step 1: Write the failing test**

```jsx
// src/pages/AccountPage.test.jsx
import { render, screen, fireEvent } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { SessionProvider } from '../context/SessionContext'
import AccountPage from './AccountPage'

test('prompts for email when not logged in, then lists purchases', () => {
  render(
    <SessionProvider>
      <MemoryRouter><AccountPage /></MemoryRouter>
    </SessionProvider>
  )
  expect(screen.getByLabelText(/email/i)).toBeInTheDocument()
  fireEvent.change(screen.getByLabelText(/email/i), { target: { value: 'buyer@x.com' } })
  fireEvent.click(screen.getByRole('button', { name: /view my books/i }))
  expect(screen.getByText(/no purchased books yet/i)).toBeInTheDocument()
})
```

- [ ] **Step 2: Run test, verify it fails**

Run: `npm test -- AccountPage`
Expected: FAIL (module not found)

- [ ] **Step 3: Implement `AccountPage.jsx`**

```jsx
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useSession } from '../context/SessionContext'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'

function AccountPage() {
  const { state, dispatch } = useSession()
  const [emailInput, setEmailInput] = useState('')

  if (!state.customerEmail) {
    return (
      <>
        <Header />
        <div className="container">
          <label htmlFor="account-email">Email</label>
          <input id="account-email" type="email" className="form-control" value={emailInput} onChange={e => setEmailInput(e.target.value)} />
          <button className="btn btn-primary" onClick={() => dispatch({ type: 'LOGIN_CUSTOMER', payload: { email: emailInput } })}>
            View My Books
          </button>
        </div>
        <Footer />
      </>
    )
  }

  const myPaidOrders = state.orders.filter(o => o.customerEmail === state.customerEmail && o.paymentStatus === 'paid')

  return (
    <>
      <Header />
      <div className="container">
        <h2>My Books</h2>
        {myPaidOrders.length === 0 && <p>No purchased books yet.</p>}
        <ul>
          {myPaidOrders.map(order => {
            const book = state.books.find(b => b.id === order.bookId)
            return (
              <li key={order.id}>
                {book?.title} ({order.purchaseType})
                {order.purchaseType === 'digital' && <Link to={`/read/${order.bookId}`}> Read</Link>}
              </li>
            )
          })}
        </ul>
      </div>
      <Footer />
    </>
  )
}

export default AccountPage
```

- [ ] **Step 4: Add route to `App.jsx`**

```jsx
<Route path="/account" element={<AccountPage />} />
```

- [ ] **Step 5: Run test, verify pass**

Run: `npm test -- AccountPage`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add customer account page listing purchased books"
```

---

### Task 14: Admin auth (AdminLoginPage + protected AdminLayout)

**Files:**
- Create: `src/pages/admin/AdminLoginPage.jsx`, `src/pages/admin/AdminLayout.jsx`
- Test: `src/pages/admin/AdminLayout.test.jsx`
- Modify: `src/App.jsx` (nest all `/admin/*` routes under `AdminLayout`)

**Interfaces:**
- Consumes: `useSession()` — reads `state.admin`, dispatches `'LOGIN_ADMIN'`.
- Produces: `AdminLayout` renders an `<Outlet />` with an admin side-nav when `state.admin` is set, otherwise renders `AdminLoginPage` — later admin page tasks (15–18) are mounted as children and assume they only render once logged in.

- [ ] **Step 1: Write the failing test**

```jsx
// src/pages/admin/AdminLayout.test.jsx
import { render, screen, fireEvent } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { SessionProvider } from '../../context/SessionContext'
import AdminLayout from './AdminLayout'

test('shows login form, then nav after valid hardcoded login', () => {
  render(
    <SessionProvider>
      <MemoryRouter initialEntries={['/admin']}>
        <Routes>
          <Route path="/admin" element={<AdminLayout />}>
            <Route index element={<div>dashboard content</div>} />
          </Route>
        </Routes>
      </MemoryRouter>
    </SessionProvider>
  )
  expect(screen.getByLabelText(/username/i)).toBeInTheDocument()
  fireEvent.change(screen.getByLabelText(/username/i), { target: { value: 'admin' } })
  fireEvent.change(screen.getByLabelText(/password/i), { target: { value: 'admin123' } })
  fireEvent.click(screen.getByRole('button', { name: /log in/i }))
  expect(screen.getByText(/dashboard content/i)).toBeInTheDocument()
})
```

- [ ] **Step 2: Run test, verify it fails**

Run: `npm test -- AdminLayout`
Expected: FAIL (module not found)

- [ ] **Step 3: Implement `AdminLoginPage.jsx`**

```jsx
import { useState } from 'react'
import { useSession } from '../../context/SessionContext'

// Mock credential check only — real authentication arrives with the Phase 2 PHP backend.
const MOCK_USERNAME = 'admin'
const MOCK_PASSWORD = 'admin123'

function AdminLoginPage() {
  const { dispatch } = useSession()
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState(false)

  const handleSubmit = (e) => {
    e.preventDefault()
    if (username === MOCK_USERNAME && password === MOCK_PASSWORD) {
      dispatch({ type: 'LOGIN_ADMIN', payload: { username } })
    } else {
      setError(true)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="container">
      <h2>Admin Login</h2>
      {error && <p className="form-error">Invalid credentials.</p>}
      <label htmlFor="admin-username">Username</label>
      <input id="admin-username" className="form-control" value={username} onChange={e => setUsername(e.target.value)} />
      <label htmlFor="admin-password">Password</label>
      <input id="admin-password" type="password" className="form-control" value={password} onChange={e => setPassword(e.target.value)} />
      <button type="submit" className="btn btn-primary">Log In</button>
    </form>
  )
}

export default AdminLoginPage
```

- [ ] **Step 4: Implement `AdminLayout.jsx`**

```jsx
import { Link, Outlet } from 'react-router-dom'
import { useSession } from '../../context/SessionContext'
import AdminLoginPage from './AdminLoginPage'

function AdminLayout() {
  const { state, dispatch } = useSession()

  if (!state.admin) return <AdminLoginPage />

  return (
    <div className="admin-layout">
      <nav>
        <Link to="/admin">Dashboard</Link>
        <Link to="/admin/books">Books</Link>
        <Link to="/admin/customers">Customers</Link>
        <Link to="/admin/referrals">Referral Partners</Link>
        <Link to="/admin/orders">Orders</Link>
        <Link to="/admin/commissions">Commissions</Link>
        <button onClick={() => dispatch({ type: 'LOGOUT_ADMIN' })}>Log Out</button>
      </nav>
      <main className="container">
        <Outlet />
      </main>
    </div>
  )
}

export default AdminLayout
```

- [ ] **Step 5: Nest admin routes in `App.jsx`**

```jsx
<Route path="/admin" element={<AdminLayout />}>
  <Route index element={<AdminDashboardPage />} />
</Route>
```

(Task 15–18 add sibling `<Route>` children here — `books`, `customers`, `referrals`, `orders`, `commissions`. Create a minimal `AdminDashboardPage.jsx` now — `function AdminDashboardPage() { return <p>Dashboard</p> }` — so this route compiles; Task 15 replaces it with the full version described below.)

Actually: create the real `AdminDashboardPage.jsx` directly in this task instead of a throwaway stub, since it needs no data from later tasks:

```jsx
// src/pages/admin/AdminDashboardPage.jsx
import { useSession } from '../../context/SessionContext'

function AdminDashboardPage() {
  const { state } = useSession()
  const totalDigital = state.orders.filter(o => o.purchaseType === 'digital' && o.paymentStatus === 'paid').length
  const totalHardCopy = state.orders.filter(o => o.purchaseType === 'hardcopy' && o.paymentStatus === 'paid').length
  const revenue = state.orders.filter(o => o.paymentStatus === 'paid').reduce((sum, o) => sum + o.amount, 0)
  const commission = state.orders
    .filter(o => o.paymentStatus === 'paid' && o.referralPartnerId)
    .reduce((sum, o) => sum + (o.purchaseType === 'digital' ? state.commissionRate.digital : state.commissionRate.hardcopy), 0)

  return (
    <div>
      <h2>Dashboard</h2>
      <ul>
        <li>Total Books: {state.books.length}</li>
        <li>Total Digital Purchases: {totalDigital}</li>
        <li>Total Hard Copy Purchases: {totalHardCopy}</li>
        <li>Total Referral Partners: {state.referralPartners.length}</li>
        <li>Total Orders: {state.orders.length}</li>
        <li>Revenue: ₹{revenue}</li>
        <li>Commission Summary: ₹{commission}</li>
      </ul>
    </div>
  )
}

export default AdminDashboardPage
```

Import it in `App.jsx` and use `<Route index element={<AdminDashboardPage />} />`.

- [ ] **Step 6: Run test, verify pass**

Run: `npm test -- AdminLayout`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: add admin login gate, layout, and dashboard stats"
```

---

### Task 15: AdminBooksPage (CRUD)

**Files:**
- Create: `src/pages/admin/AdminBooksPage.jsx`
- Test: `src/pages/admin/AdminBooksPage.test.jsx`
- Modify: `src/App.jsx` (add child route `books` under `/admin`)

**Interfaces:**
- Consumes: `useSession()` — reads `state.books`, dispatches `'ADD_BOOK'`, `'UPDATE_BOOK'`, `'DELETE_BOOK'`, `'TOGGLE_PUBLISH'` (Task 8).

- [ ] **Step 1: Write the failing test**

```jsx
// src/pages/admin/AdminBooksPage.test.jsx
import { render, screen, fireEvent } from '@testing-library/react'
import { SessionProvider } from '../../context/SessionContext'
import AdminBooksPage from './AdminBooksPage'

test('adding a book appends it unpublished, then publish toggles it', () => {
  render(<SessionProvider><AdminBooksPage /></SessionProvider>)

  fireEvent.change(screen.getByLabelText(/title/i), { target: { value: 'New Book' } })
  fireEvent.change(screen.getByLabelText(/online price/i), { target: { value: '100' } })
  fireEvent.change(screen.getByLabelText(/hard copy price/i), { target: { value: '200' } })
  fireEvent.click(screen.getByRole('button', { name: /add book/i }))

  const row = screen.getByText('New Book').closest('tr')
  expect(row).toHaveTextContent(/unpublished/i)

  fireEvent.click(within(row).getByRole('button', { name: /publish/i }))
  expect(row).toHaveTextContent(/published/i)
})
```

Add `import { within } from '@testing-library/react'` alongside the other RTL imports in this file.

- [ ] **Step 2: Run test, verify it fails**

Run: `npm test -- AdminBooksPage`
Expected: FAIL (module not found)

- [ ] **Step 3: Implement `AdminBooksPage.jsx`**

```jsx
import { useState } from 'react'
import { useSession } from '../../context/SessionContext'

function AdminBooksPage() {
  const { state, dispatch } = useSession()
  const [title, setTitle] = useState('')
  const [onlinePrice, setOnlinePrice] = useState('')
  const [hardCopyPrice, setHardCopyPrice] = useState('')

  const handleAdd = (e) => {
    e.preventDefault()
    dispatch({
      type: 'ADD_BOOK',
      payload: {
        title,
        description: '',
        type: 'General',
        coverUrl: '/assets/logo.jpeg',
        onlinePrice: Number(onlinePrice),
        hardCopyPrice: Number(hardCopyPrice),
        pageImages: [],
      },
    })
    setTitle('')
    setOnlinePrice('')
    setHardCopyPrice('')
  }

  return (
    <div>
      <h2>Books</h2>
      <form onSubmit={handleAdd}>
        <label htmlFor="new-title">Title</label>
        <input id="new-title" className="form-control" value={title} onChange={e => setTitle(e.target.value)} required />
        <label htmlFor="new-online-price">Online Price</label>
        <input id="new-online-price" type="number" className="form-control" value={onlinePrice} onChange={e => setOnlinePrice(e.target.value)} required />
        <label htmlFor="new-hardcopy-price">Hard Copy Price</label>
        <input id="new-hardcopy-price" type="number" className="form-control" value={hardCopyPrice} onChange={e => setHardCopyPrice(e.target.value)} required />
        <button type="submit" className="btn btn-primary">Add Book</button>
      </form>

      <table>
        <thead>
          <tr><th>Title</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          {state.books.map(book => (
            <tr key={book.id}>
              <td>{book.title}</td>
              <td>{book.published ? 'Published' : 'Unpublished'}</td>
              <td>
                <button onClick={() => dispatch({ type: 'TOGGLE_PUBLISH', payload: { id: book.id } })}>
                  {book.published ? 'Unpublish' : 'Publish'}
                </button>
                <button onClick={() => dispatch({ type: 'DELETE_BOOK', payload: { id: book.id } })}>Delete</button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export default AdminBooksPage
```

- [ ] **Step 4: Add route to `App.jsx`**

```jsx
<Route path="books" element={<AdminBooksPage />} />
```

(As a child of the `/admin` `<Route>` from Task 14 — no leading slash.)

- [ ] **Step 5: Run test, verify pass**

Run: `npm test -- AdminBooksPage`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add admin book management (add/publish/delete)"
```

---

### Task 16: AdminCustomersPage + AdminOrdersPage

**Files:**
- Create: `src/pages/admin/AdminCustomersPage.jsx`, `src/pages/admin/AdminOrdersPage.jsx`
- Test: `src/pages/admin/AdminOrdersPage.test.jsx`
- Modify: `src/App.jsx` (add child routes `customers`, `orders` under `/admin`)

**Interfaces:**
- Consumes: `useSession()` — reads `state.orders`, `state.books`, `state.referralPartners`.

- [ ] **Step 1: Write the failing test**

```jsx
// src/pages/admin/AdminOrdersPage.test.jsx
import { render, screen, fireEvent } from '@testing-library/react'
import { SessionProvider, useSession } from '../../context/SessionContext'
import AdminOrdersPage from './AdminOrdersPage'

function Seed({ children }) {
  const { dispatch } = useSession()
  dispatch({ type: 'CREATE_ORDER', payload: { bookId: 'b1', customerEmail: 'x@y.com', purchaseType: 'digital', amount: 199, referralPartnerId: null } })
  dispatch({ type: 'CREATE_ORDER', payload: { bookId: 'b1', customerEmail: 'x@y.com', purchaseType: 'hardcopy', amount: 349, referralPartnerId: null } })
  return children
}

test('filters orders by purchase type', () => {
  render(
    <SessionProvider>
      <Seed>
        <AdminOrdersPage />
      </Seed>
    </SessionProvider>
  )
  expect(screen.getAllByRole('row').length).toBe(3) // header + 2 orders
  fireEvent.change(screen.getByLabelText(/filter by type/i), { target: { value: 'digital' } })
  expect(screen.getAllByRole('row').length).toBe(2) // header + 1 digital order
})
```

Note: `Seed` dispatches on every render, which is fine for this test (React 18 test renders synchronously once via RTL's `render`), but is not a pattern to reuse outside tests — production pages read state, they don't seed it.

- [ ] **Step 2: Run test, verify it fails**

Run: `npm test -- AdminOrdersPage`
Expected: FAIL (module not found)

- [ ] **Step 3: Implement `AdminOrdersPage.jsx`**

```jsx
import { useState } from 'react'
import { useSession } from '../../context/SessionContext'

function AdminOrdersPage() {
  const { state } = useSession()
  const [typeFilter, setTypeFilter] = useState('all')

  const filtered = state.orders.filter(o => typeFilter === 'all' || o.purchaseType === typeFilter)

  return (
    <div>
      <h2>Orders</h2>
      <label htmlFor="type-filter">Filter by type</label>
      <select id="type-filter" value={typeFilter} onChange={e => setTypeFilter(e.target.value)}>
        <option value="all">All</option>
        <option value="digital">Digital</option>
        <option value="hardcopy">Hard Copy</option>
      </select>

      <table>
        <thead>
          <tr><th>Order</th><th>Book</th><th>Customer</th><th>Type</th><th>Status</th><th>Amount</th><th>Referral</th></tr>
        </thead>
        <tbody>
          {filtered.map(order => {
            const book = state.books.find(b => b.id === order.bookId)
            const partner = state.referralPartners.find(p => p.id === order.referralPartnerId)
            return (
              <tr key={order.id}>
                <td>{order.id}</td>
                <td>{book?.title}</td>
                <td>{order.customerEmail}</td>
                <td>{order.purchaseType}</td>
                <td>{order.paymentStatus}</td>
                <td>₹{order.amount}</td>
                <td>{partner?.name ?? '—'}</td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

export default AdminOrdersPage
```

- [ ] **Step 4: Implement `AdminCustomersPage.jsx`**

```jsx
import { useSession } from '../../context/SessionContext'

function AdminCustomersPage() {
  const { state } = useSession()
  const byCustomer = {}
  for (const order of state.orders) {
    if (!byCustomer[order.customerEmail]) byCustomer[order.customerEmail] = []
    byCustomer[order.customerEmail].push(order)
  }

  return (
    <div>
      <h2>Customers</h2>
      <table>
        <thead>
          <tr><th>Email</th><th>Orders</th><th>Total Spent (paid)</th></tr>
        </thead>
        <tbody>
          {Object.entries(byCustomer).map(([email, orders]) => (
            <tr key={email}>
              <td>{email}</td>
              <td>{orders.length}</td>
              <td>₹{orders.filter(o => o.paymentStatus === 'paid').reduce((s, o) => s + o.amount, 0)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export default AdminCustomersPage
```

- [ ] **Step 5: Add routes to `App.jsx`**

```jsx
<Route path="customers" element={<AdminCustomersPage />} />
<Route path="orders" element={<AdminOrdersPage />} />
```

- [ ] **Step 6: Run test, verify pass**

Run: `npm test -- AdminOrdersPage`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: add admin customers and orders pages"
```

---

### Task 17: AdminReferralsPage

**Files:**
- Create: `src/pages/admin/AdminReferralsPage.jsx`
- Test: `src/pages/admin/AdminReferralsPage.test.jsx`
- Modify: `src/App.jsx` (add child route `referrals` under `/admin`)

**Interfaces:**
- Consumes: `useSession()` — reads `state.referralPartners`, `state.orders`; dispatches `'APPROVE_REFERRAL'`, `'REJECT_REFERRAL'`, `'TOGGLE_REFERRAL_ACTIVE'` (Task 8).

- [ ] **Step 1: Write the failing test**

```jsx
// src/pages/admin/AdminReferralsPage.test.jsx
import { render, screen } from '@testing-library/react'
import { within } from '@testing-library/react'
import { SessionProvider } from '../../context/SessionContext'
import AdminReferralsPage from './AdminReferralsPage'
import { fireEvent } from '@testing-library/react'

test('approving a pending partner marks it approved and active', () => {
  render(<SessionProvider><AdminReferralsPage /></SessionProvider>)
  const row = screen.getByText('Arun S').closest('tr')
  expect(row).toHaveTextContent(/pending/i)
  fireEvent.click(within(row).getByRole('button', { name: /approve/i }))
  expect(row).toHaveTextContent(/approved/i)
})
```

- [ ] **Step 2: Run test, verify it fails**

Run: `npm test -- AdminReferralsPage`
Expected: FAIL (module not found)

- [ ] **Step 3: Implement `AdminReferralsPage.jsx`**

```jsx
import { useSession } from '../../context/SessionContext'

function AdminReferralsPage() {
  const { state, dispatch } = useSession()

  return (
    <div>
      <h2>Referral Partners</h2>
      <table>
        <thead>
          <tr><th>Name</th><th>Code</th><th>Status</th><th>Active</th><th>Referrals</th><th>Actions</th></tr>
        </thead>
        <tbody>
          {state.referralPartners.map(p => {
            const referralCount = state.orders.filter(o => o.referralPartnerId === p.id).length
            return (
              <tr key={p.id}>
                <td>{p.name}</td>
                <td>{p.referralCode}</td>
                <td>{p.approved ? 'Approved' : 'Pending'}</td>
                <td>{p.active ? 'Active' : 'Inactive'}</td>
                <td>{referralCount}</td>
                <td>
                  {!p.approved && (
                    <>
                      <button onClick={() => dispatch({ type: 'APPROVE_REFERRAL', payload: { id: p.id } })}>Approve</button>
                      <button onClick={() => dispatch({ type: 'REJECT_REFERRAL', payload: { id: p.id } })}>Reject</button>
                    </>
                  )}
                  {p.approved && (
                    <button onClick={() => dispatch({ type: 'TOGGLE_REFERRAL_ACTIVE', payload: { id: p.id } })}>
                      {p.active ? 'Deactivate' : 'Activate'}
                    </button>
                  )}
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

export default AdminReferralsPage
```

- [ ] **Step 4: Add route to `App.jsx`**

```jsx
<Route path="referrals" element={<AdminReferralsPage />} />
```

- [ ] **Step 5: Run test, verify pass**

Run: `npm test -- AdminReferralsPage`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add admin referral partner approval/activation page"
```

---

### Task 18: AdminCommissionsPage

**Files:**
- Create: `src/pages/admin/AdminCommissionsPage.jsx`
- Test: `src/pages/admin/AdminCommissionsPage.test.jsx`
- Modify: `src/App.jsx` (add child route `commissions` under `/admin`)

**Interfaces:**
- Consumes: `useSession()` — reads `state.orders`, `state.referralPartners`, `state.commissionRate`; dispatches `'SET_COMMISSION_RATE'` (Task 8).

- [ ] **Step 1: Write the failing test**

```jsx
// src/pages/admin/AdminCommissionsPage.test.jsx
import { render, screen, fireEvent } from '@testing-library/react'
import { SessionProvider, useSession } from '../../context/SessionContext'
import AdminCommissionsPage from './AdminCommissionsPage'

function Seed({ children }) {
  const { dispatch } = useSession()
  dispatch({ type: 'CREATE_ORDER', payload: { bookId: 'b1', customerEmail: 'x@y.com', purchaseType: 'digital', amount: 199, referralPartnerId: 'r1' } })
  return children
}

test('only counts commission for paid orders with a referral partner', () => {
  render(
    <SessionProvider>
      <Seed><AdminCommissionsPage /></Seed>
    </SessionProvider>
  )
  // Order created above is still 'pending' — no commission should show yet.
  expect(screen.getByText(/total commission: ₹0/i)).toBeInTheDocument()
})

test('commission rate is editable', () => {
  render(<SessionProvider><AdminCommissionsPage /></SessionProvider>)
  fireEvent.change(screen.getByLabelText(/digital commission/i), { target: { value: '30' } })
  fireEvent.click(screen.getByRole('button', { name: /save rates/i }))
  expect(screen.getByLabelText(/digital commission/i)).toHaveValue(30)
})
```

- [ ] **Step 2: Run test, verify it fails**

Run: `npm test -- AdminCommissionsPage`
Expected: FAIL (module not found)

- [ ] **Step 3: Implement `AdminCommissionsPage.jsx`**

```jsx
import { useState } from 'react'
import { useSession } from '../../context/SessionContext'

function AdminCommissionsPage() {
  const { state, dispatch } = useSession()
  const [digitalRate, setDigitalRate] = useState(state.commissionRate.digital)
  const [hardcopyRate, setHardcopyRate] = useState(state.commissionRate.hardcopy)

  const commissionEntries = state.orders
    .filter(o => o.paymentStatus === 'paid' && o.referralPartnerId)
    .map(o => {
      const partner = state.referralPartners.find(p => p.id === o.referralPartnerId)
      const rate = o.purchaseType === 'digital' ? state.commissionRate.digital : state.commissionRate.hardcopy
      return { orderId: o.id, partnerName: partner?.name, rate }
    })

  const total = commissionEntries.reduce((s, e) => s + e.rate, 0)

  const handleSave = (e) => {
    e.preventDefault()
    dispatch({ type: 'SET_COMMISSION_RATE', payload: { digital: Number(digitalRate), hardcopy: Number(hardcopyRate) } })
  }

  return (
    <div>
      <h2>Commissions</h2>
      <form onSubmit={handleSave}>
        <label htmlFor="digital-rate">Digital Commission (₹)</label>
        <input id="digital-rate" type="number" value={digitalRate} onChange={e => setDigitalRate(e.target.value)} />
        <label htmlFor="hardcopy-rate">Hard Copy Commission (₹)</label>
        <input id="hardcopy-rate" type="number" value={hardcopyRate} onChange={e => setHardcopyRate(e.target.value)} />
        <button type="submit" className="btn btn-primary">Save Rates</button>
      </form>

      <p>Total Commission: ₹{total}</p>
      <table>
        <thead><tr><th>Order</th><th>Partner</th><th>Commission</th></tr></thead>
        <tbody>
          {commissionEntries.map(e => (
            <tr key={e.orderId}><td>{e.orderId}</td><td>{e.partnerName}</td><td>₹{e.rate}</td></tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export default AdminCommissionsPage
```

- [ ] **Step 4: Add route to `App.jsx`**

```jsx
<Route path="commissions" element={<AdminCommissionsPage />} />
```

- [ ] **Step 5: Run test, verify pass**

Run: `npm test -- AdminCommissionsPage`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add admin commission tracking page"
```

---

### Task 19: Full suite + full manual walkthrough

**Files:** none (verification only)

- [ ] **Step 1: Run the full automated test suite**

Run: `npm test`
Expected: PASS (all tests across all tasks)

- [ ] **Step 2: Manual click-through per spec's Testing section**

Run `npm run dev` and walk the customer flow: browse `/books` → open a book → register a referral partner at `/referral/register` → approve it at `/admin/referrals` (log in at `/admin` first) → check out the book with that partner selected → confirm payment → read the digital book at `/read/:bookId` → view it under `/account`. Then in admin: check `/admin/dashboard` stats update, `/admin/orders` shows the order with the right referral partner, `/admin/commissions` shows the ₹25 commission once the order is paid.

- [ ] **Step 3: Compare homepage against the original**

Reload `/` and compare against `git show ad7d001:index.html` rendered standalone (or a pre-conversion screenshot) — confirm no visual regressions.

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "chore: phase 1 complete — React frontend with mock book sales/referral flows"
```
