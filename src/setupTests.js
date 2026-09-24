import '@testing-library/jest-dom'

// ponytail: jsdom has no IntersectionObserver; stub it so components that
// use it (e.g. HomePage's scroll-reveal effect) don't crash under test.
global.IntersectionObserver = class IntersectionObserver {
  observe() {}
  unobserve() {}
  disconnect() {}
}
