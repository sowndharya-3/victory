import '@testing-library/jest-dom'

// ponytail: jsdom has no IntersectionObserver; stub it so components that
// use it (e.g. HomePage's scroll-reveal effect) don't crash under test.
global.IntersectionObserver = class IntersectionObserver {
  observe() {}
  unobserve() {}
  disconnect() {}
}

// ponytail: SessionContext now persists to localStorage — clear it between
// tests so each test starts from the seed state, not a prior test's writes.
beforeEach(() => {
  localStorage.clear()
})
