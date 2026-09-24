import { useEffect } from 'react'
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
  // ponytail: original site reveals .fade-in/.slide-in-left/.slide-in-right/.zoom-in
  // elements via a document-wide IntersectionObserver (see ad7d001:index.html ~L2473).
  // Ported verbatim here instead of per-component — it's one cross-cutting behavior,
  // not per-section logic. Skips the hero's extra 200ms-stagger timing (same observer
  // still reveals it, just without the manual delay); add a dedicated effect in Hero if that nuance matters.
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
  }, [])

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
