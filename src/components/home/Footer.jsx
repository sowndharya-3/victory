function openOfficeMap() {
  const address = '14/13, TNHB Lake View Apartments, Sholinganallur, Chennai-600 119'
  const encodedAddress = encodeURIComponent(address)
  window.open(`https://www.google.com/maps/search/?api=1&query=${encodedAddress}`, '_blank')
}

function openResidenceMap() {
  const address = '28 G1, Vasanth Apartments, Thiruveethi Amman Koil Street, Kalikundram, Taramani - Post, Chennai-600 113'
  const encodedAddress = encodeURIComponent(address)
  window.open(`https://www.google.com/maps/search/?api=1&query=${encodedAddress}`, '_blank')
}

function makeCall() {
  window.open('tel:+919840546117', '_self')
}

function sendEmail() {
  const email = 'info@victorytoday.edu'
  const subject = 'Enquiry - Victory Today Educational Enterprises'
  const body = 'Dear Victory Today Team,\n\nI would like to get more information about your services.\n\nBest regards,'
  window.open(`mailto:${email}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`, '_blank')
}

function Footer() {
  return (
    <>
      <footer>
        <div className="container">
          <div className="footer-container">
            <div className="footer-col fade-in">
              <h3>Victory Today</h3>
              <p className="tamil">விடாமுயற்சி - தொடர் பயிற்சி - வெற்றி நிச்சயம்</p>
              <p>Providing world-class educational services with personalized approach and professional ethics.</p>
              <div className="social-links">
                <a href="#" className="social-icon"><i className="fab fa-facebook-f"></i></a>
                <a href="#" className="social-icon"><i className="fab fa-twitter"></i></a>
                <a href="#" className="social-icon"><i className="fab fa-instagram"></i></a>
                <a href="#" className="social-icon"><i className="fab fa-linkedin-in"></i></a>
                <a href="#" className="social-icon"><i className="fab fa-whatsapp"></i></a>
              </div>
            </div>

            <div className="footer-col fade-in" style={{ transitionDelay: '0.1s' }}>
              <h3>Quick Links</h3>
              <ul>
                <li><a href="#home">Home</a></li>
                <li><a href="#founder">Founder</a></li>
                <li><a href="#services">Services</a></li>
                <li><a href="#mission-vision">Mission & Vision</a></li>
                <li><a href="#contact">Contact</a></li>
              </ul>
            </div>

            <div className="footer-col fade-in" style={{ transitionDelay: '0.2s' }}>
              <h3>Our Services</h3>
              <ul>
                <li><a href="#services">Distance Education</a></li>
                <li><a href="#services">Regular Education</a></li>
                <li><a href="#services">Research & Development</a></li>
                <li><a href="#services">Competitive Exams</a></li>
                <li><a href="#services">Guidance & Counselling</a></li>
              </ul>
            </div>

            <div className="footer-col fade-in" style={{ transitionDelay: '0.3s' }}>
              <h3>Contact Info</h3>
              <ul>
                <li className="clickable-contact" onClick={openOfficeMap}>
                  <i className="fas fa-building"></i> 14/13, TNHB Lake View, Sholinganallur, Chennai-600 119
                </li>
                <li className="clickable-contact" onClick={openResidenceMap}>
                  <i className="fas fa-map-marker-alt"></i> 28 G1, Vasanth Apartments, Kalikundram, Chennai-600 113
                </li>
                <li className="clickable-contact" onClick={makeCall}>
                  <i className="fas fa-phone"></i> 9840546117 / 7845946117
                </li>
                <li className="clickable-contact" onClick={sendEmail}>
                  <i className="fas fa-envelope"></i> info@victorytoday.edu
                </li>
                <li>
                  <i className="fab fa-whatsapp"></i> WhatsApp: 9445430215
                </li>
                <li>
                  <i className="fas fa-file-alt"></i> Udyam Registration: TN-02-0260305
                </li>
                <li>
                  <i className="fas fa-file-invoice"></i> GST Number: 33BKMPS8196J1Z4
                </li>
              </ul>
            </div>
          </div>

          <div className="copyright fade-in">
            <p>&copy; 2025 Victory Today Educational Enterprises. All Rights Reserved.</p>
            <p>THIS IS AN CHANGES IN SERVICES</p>
          </div>
        </div>
      </footer>

      <a href="https://wa.me/919445430215" className="whatsapp-float" target="_blank" rel="noreferrer" aria-label="Chat on WhatsApp">
        <i className="fab fa-whatsapp"></i>
      </a>
    </>
  )
}

export default Footer
