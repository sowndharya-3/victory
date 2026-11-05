import { render, screen, cleanup, within } from '@testing-library/react'
import { vi } from 'vitest'
import App from '../App'
import { POLICIES } from '../content/policies'

beforeEach(() => vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {}))))
afterEach(() => { cleanup(); vi.unstubAllGlobals(); window.history.pushState({}, '', '/') })

test.each(POLICIES.map(p => [p.path, p.title]))('%s renders %s with draft banner', (path, title) => {
  window.history.pushState({}, '', path)
  render(<App />)
  expect(screen.getByRole('heading', { level: 1, name: title })).toBeInTheDocument()
  expect(screen.getByText(/draft for business review/i)).toBeInTheDocument()
  expect(screen.getByText(/last updated:/i)).toBeInTheDocument()
  expect(screen.queryByText(/page not found/i)).toBeNull()
})

test('footer links point to every policy route', () => {
  window.history.pushState({}, '', '/terms')
  render(<App />)
  const footer = document.querySelector('footer')
  for (const p of POLICIES) {
    expect(within(footer).getAllByRole('link').some(a => a.getAttribute('href') === p.path)).toBe(true)
  }
})

test('refund policy promises no timelines', () => {
  expect(JSON.stringify(POLICIES.find(p => p.path === '/refund-policy'))).not.toMatch(/\d+\s*(days|hours|business)/i)
})
