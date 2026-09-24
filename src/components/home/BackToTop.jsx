import { useEffect, useState } from 'react'

function BackToTop() {
  const [visible, setVisible] = useState(false)

  useEffect(() => {
    const onScroll = () => setVisible(window.pageYOffset > 300)
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
