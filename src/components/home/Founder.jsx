import { useState } from 'react'

function Founder() {
  const [imageFailed, setImageFailed] = useState(false)

  return (
    <section className="founder-section" id="founder">
      <div className="container">
        <div className="section-title">
          <h2 className="fade-in">Our Founder</h2>
          <p className="fade-in">Meet the visionary behind Victory Today Educational Enterprises</p>
        </div>

        <div className="founder-container">
          <div className="founder-header fade-in">
            <h3 className="founder-name">Dr. T. Sampath Kumar</h3>
            <h4 className="founder-qualification">PhD in Education</h4>
            <p className="founder-title">Founder Chairman, Victory Today Educational Enterprises</p>
          </div>

          <div className="founder-content">
            <div className="founder-image-container slide-in-left">
              {imageFailed ? (
                <div className="founder-img-placeholder">
                  Dr. T. Sampath Kumar<br />PhD in Education<br />Founder Chairman
                </div>
              ) : (
                <img
                  src="/assets/founder.jpeg"
                  alt="Dr. T. Sampath Kumar"
                  className="founder-img"
                  id="founderImage"
                  onError={() => setImageFailed(true)}
                />
              )}
            </div>

            <div className="founder-details slide-in-right">
              <p>Senior Faculty in Education (R), Member Syndicate (F), Board of Studies Member (F), Academic Council Member (F), Director i/c (F), CUIIC Tamilnadu Open University, Educationist In Pothigai, kalaignar Television and All India Radio</p>

              <p>Udyam Registration No: TN-02-0260305 Date: 12.12.2023</p>
              <p>GST Number: 33BKMPS8196J1Z4</p>

              <div className="founder-profile">
                <h4>Founder Profile</h4>
                <ul>
                  <li>Completed 7 Major Research Projects</li>
                  <li>Written 18 University Textbooks</li>
                  <li>Authored 25 Own Books</li>
                  <li>Published 48 Journals</li>
                </ul>
              </div>

              <div className="founder-credentials">
                <div className="credential-item zoom-in">
                  <div className="credential-icon">
                    <i className="fas fa-building"></i>
                  </div>
                  <h4>Office Address</h4>
                  <p>14/13, TNHB Lake View Apartments, Sholinganallur, Chennai-600 119</p>
                </div>

                <div className="credential-item zoom-in">
                  <div className="credential-icon">
                    <i className="fas fa-map-marker-alt"></i>
                  </div>
                  <h4>Residential Address</h4>
                  <p>28 G1, Vasanth Apartments, Thiruveethi Amman Koil Street, Kalikundram, Taramani - Post, Chennai-600 113</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  )
}

export default Founder
