import { render, screen, cleanup } from '@testing-library/react'
import { vi } from 'vitest'
import App from './App'

// Deep links the static host rewrites to index.html (public/.htaccess, public/_redirects).
// Each must resolve to a real page on first load, with no token in storage.
const deepLinks = [
  ['/books', /^books$/i],
  ['/books/2', /loading/i],
  ['/checkout/2', /loading/i],
  ['/orders/5/result', /log in to see your order/i],
  ['/my-books', /log in to see your books/i],
  ['/read/2', /log in to read this book/i],
  ['/account', /^log in$/i],
  ['/track', /track your order/i],
  ['/referral/register', /become a referral partner/i],
  ['/admin', /admin login/i],
  ['/admin/orders', /admin login/i],
  ['/admin/settings', /admin login/i],
]

beforeEach(() => {
  vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {}))) // API never answers: we only test routing
})
afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
  window.history.pushState({}, '', '/')
})

test.each(deepLinks)('direct load of %s renders its page, not the not-found page', (path, text) => {
  window.history.pushState({}, '', path)
  render(<App />)
  expect(screen.getAllByText(text).length).toBeGreaterThan(0)
  expect(screen.queryByText(/page not found/i)).not.toBeInTheDocument()
})

test.each(['/nope', '/admin/nope', '/read'])('unknown path %s shows the not-found page', path => {
  window.history.pushState({}, '', path)
  render(<App />)
  expect(screen.getByText(/page not found/i)).toBeInTheDocument()
  expect(screen.getByRole('link', { name: /go to the home page/i })).toHaveAttribute('href', '/')
})
