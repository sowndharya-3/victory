import { render, screen, fireEvent } from '@testing-library/react'
import App from './App'

test('renders home page heading', () => {
  render(<App />)
  expect(screen.getAllByText(/victory today educational enterprises/i).length).toBeGreaterThan(0)
})

test('scroll-reveal observes elements on every route, not just the first', () => {
  const observedByPage = []
  const originalIO = global.IntersectionObserver
  global.IntersectionObserver = class {
    observe(el) {
      observedByPage.push(el)
    }
    unobserve() {}
    disconnect() {}
  }

  try {
    render(<App />)
    const homeObservedCount = observedByPage.length
    expect(homeObservedCount).toBeGreaterThan(0)

    fireEvent.click(screen.getByRole('link', { name: /^books$/i }))

    // Navigating client-side must trigger a fresh observe pass for BooksPage's
    // own Footer instance — a mount-once effect (the bug being tested for)
    // would leave this count unchanged after navigation.
    expect(observedByPage.length).toBeGreaterThan(homeObservedCount)
  } finally {
    global.IntersectionObserver = originalIO
  }
})
