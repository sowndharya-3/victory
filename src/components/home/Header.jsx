import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'

function Header() {
  const [menuOpen, setMenuOpen] = useState(false)
  const containerRef = useRef(null)

  const closeMenu = () => setMenuOpen(false)

  useEffect(() => {
    document.body.style.overflow = menuOpen ? 'hidden' : ''
    return () => {
      document.body.style.overflow = ''
    }
  }, [menuOpen])

  useEffect(() => {
    const handleOutsideClick = (e) => {
      if (window.innerWidth <= 768 && !containerRef.current?.contains(e.target)) {
        closeMenu()
      }
    }
    document.addEventListener('click', handleOutsideClick)
    return () => document.removeEventListener('click', handleOutsideClick)
  }, [])

  return (
    <header>
      <div className="container header-container" ref={containerRef}>
        <div className="logo-section">
          <img
            src="/assets/logo.jpeg"
            alt="Victory Today Educational Enterprises Logo"
            className="logo"
            onError={(e) => {
              e.currentTarget.onerror = null
              e.currentTarget.src = 'https://via.placeholder.com/180x70/9B1B30/FFF9C4?text=VTE+Logo'
            }}
          />
          <div className="logo-text">
            <h1>Victory Today Educational Enterprises</h1>
            <p className="tamil">விடாமுயற்சி - தொடர் பயிற்சி - வெற்றி நிச்சயம்</p>
          </div>
        </div>

        <button
          className="mobile-menu-btn"
          aria-label="Toggle navigation menu"
          onClick={() => setMenuOpen((open) => !open)}
        >
          <i className={menuOpen ? 'fas fa-times' : 'fas fa-bars'}></i>
        </button>

        <ul className={`nav-menu${menuOpen ? ' active' : ''}`}>
          <li><Link to="/" onClick={closeMenu}>Home</Link></li>
          <li><a href="/#founder" onClick={closeMenu}>Founder</a></li>
          <li><a href="/#services" onClick={closeMenu}>Services</a></li>
          <li><a href="/#mission-vision" onClick={closeMenu}>Mission & Vision</a></li>
          <li><a href="/#contact" onClick={closeMenu}>Contact</a></li>
          <li><Link to="/books" onClick={closeMenu}>Books</Link></li>
          <li><Link to="/account" onClick={closeMenu}>My Books</Link></li>
          <li><Link to="/referral/register" onClick={closeMenu}>Become a Referral Partner</Link></li>
        </ul>
      </div>
    </header>
  )
}

export default Header
