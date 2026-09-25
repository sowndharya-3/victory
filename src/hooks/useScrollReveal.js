import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'

// ponytail: original site reveals .fade-in/.slide-in-left/.slide-in-right/.zoom-in
// elements via a document-wide IntersectionObserver (see ad7d001:index.html ~L2473).
// Extracted here (rather than left in HomePage) so it applies across every route —
// Footer and other shared components use the same classes and are rendered on
// pages other than HomePage. Skips the hero's extra 200ms-stagger timing (same
// observer still reveals it, just without the manual delay).
//
// Re-runs on every route change (keyed on location.pathname): React Router's
// client-side navigation swaps only the matched route's subtree, not this
// component, so a mount-once effect would only ever see the DOM present at
// the very first page load and never observe elements from pages navigated
// to afterward (e.g. Footer re-rendered fresh on /books, /checkout, etc.).
// Already-`.visible` elements are untouched by the disconnect/reconnect.
export function useScrollReveal() {
  const location = useLocation()

  useEffect(() => {
    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) entry.target.classList.add('visible')
        })
      },
      { threshold: 0.1, rootMargin: '0px 0px -50px 0px' }
    )
    document
      .querySelectorAll('.fade-in, .slide-in-left, .slide-in-right, .zoom-in')
      .forEach((el) => observer.observe(el))
    return () => observer.disconnect()
  }, [location.pathname])
}
