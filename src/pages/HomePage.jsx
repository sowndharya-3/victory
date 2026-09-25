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
  // Scroll-reveal is now applied once at App level (useScrollReveal there
  // covers every route, Footer included) — nothing to do here.
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
