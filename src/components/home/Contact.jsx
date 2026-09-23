import { useState } from 'react'

const SERVICE_OPTIONS = [
  { value: 'distance-education', label: 'Distance Education Admission' },
  { value: 'regular-education', label: 'Regular Education Admission' },
  { value: 'content-writing', label: 'Content Writing / Translation' },
  { value: 'books-writing', label: 'Books Writing' },
  { value: 'research', label: 'Research and Development' },
  { value: 'competitive-exams', label: 'Competitive Exam Preparation' },
  { value: 'entrepreneurship', label: 'Entrepreneurship' },
  { value: 'ngo-registration', label: 'NGO/ Society Registration' },
  { value: 'scholarship', label: 'Scholarship/Stipend Assistance' },
  { value: 'books-sale', label: 'Sale of Victory Today Publications' },
  { value: 'career-consultancy', label: 'Career Consultancy' },
  { value: 'guidance-counselling', label: 'Guidance and Counselling' },
  { value: 'stationary-devices', label: 'Stationaries & Computer Devices' },
]

const INITIAL_FORM = {
  name: '',
  phone: '',
  email: '',
  service: '',
  details: '',
  counselingTime: '',
  facilitator: 'No',
}

function Contact() {
  const [form, setForm] = useState(INITIAL_FORM)
  const [submitted, setSubmitted] = useState(false)
  const [error, setError] = useState(false)

  const update = (field) => (e) => setForm((f) => ({ ...f, [field]: e.target.value }))

  const handleSubmit = (e) => {
    e.preventDefault()
    if (!form.name || !form.phone || !form.email || !form.service) {
      setError(true)
      setSubmitted(false)
      return
    }
    setError(false)
    setSubmitted(true)
    setForm(INITIAL_FORM)
  }

  return (
    <section className="contact-section" id="contact">
      <div className="container">
        <div className="section-title">
          <h2 className="fade-in">Contact Us</h2>
          <p className="fade-in">Get in touch with us for free counselling and services</p>
        </div>

        <div className="contact-container">
          <div className="contact-form slide-in-left">
            <h3>Enquiry Form</h3>

            {submitted && (
              <div className="form-success">
                <i className="fas fa-check-circle"></i> Thank you! Your enquiry has been submitted successfully. We will contact you shortly.
              </div>
            )}

            {error && (
              <div className="form-error">
                <i className="fas fa-exclamation-circle"></i> Please fill all required fields.
              </div>
            )}

            <form onSubmit={handleSubmit} noValidate>
              <div className="form-group">
                <label htmlFor="name">Name:</label>
                <input type="text" id="name" className="form-control" value={form.name} onChange={update('name')} required />
              </div>

              <div className="form-group">
                <label htmlFor="phone">Phone:</label>
                <input type="tel" id="phone" className="form-control" value={form.phone} onChange={update('phone')} required />
              </div>

              <div className="form-group">
                <label htmlFor="email">E-mail:</label>
                <input type="email" id="email" className="form-control" value={form.email} onChange={update('email')} required />
              </div>

              <div className="form-group">
                <label htmlFor="service">Service you want:</label>
                <select id="service" className="form-control" value={form.service} onChange={update('service')} required>
                  <option value="">Select a service</option>
                  {SERVICE_OPTIONS.map((opt) => (
                    <option key={opt.value} value={opt.value}>
                      {opt.label}
                    </option>
                  ))}
                </select>
              </div>

              <div className="form-group">
                <label htmlFor="details">Details:</label>
                <textarea
                  id="details"
                  className="form-control"
                  placeholder="Please provide more details about your requirement"
                  value={form.details}
                  onChange={update('details')}
                ></textarea>
              </div>

              <div className="form-group">
                <label htmlFor="counseling-time">Fix free counselling time:</label>
                <input type="datetime-local" id="counseling-time" className="form-control" value={form.counselingTime} onChange={update('counselingTime')} />
              </div>

              <div className="form-group">
                <label>If you are willing to be a Service Facilitator (% basis):</label>
                <div className="radio-group">
                  <div className="radio-option">
                    <input
                      type="radio"
                      id="facilitator-yes"
                      name="facilitator"
                      value="Yes"
                      checked={form.facilitator === 'Yes'}
                      onChange={update('facilitator')}
                    />
                    <label htmlFor="facilitator-yes">Yes</label>
                  </div>
                  <div className="radio-option">
                    <input
                      type="radio"
                      id="facilitator-no"
                      name="facilitator"
                      value="No"
                      checked={form.facilitator === 'No'}
                      onChange={update('facilitator')}
                    />
                    <label htmlFor="facilitator-no">No</label>
                  </div>
                </div>
                <div className="facilitator-info">
                  <i className="fas fa-info-circle"></i> Service Facilitators work on commission basis and help connect clients with our services.
                </div>
              </div>

              <button type="submit" className="btn btn-primary" style={{ width: '100%' }}>
                Submit Enquiry
              </button>
            </form>
          </div>

          <div className="contact-info">
            <h3 className="fade-in">Contact Information</h3>

            <div className="contact-detail slide-in-right">
              <div className="contact-icon">
                <i className="fas fa-building"></i>
              </div>
              <div>
                <h4>Office Address</h4>
                <p>14/13, TNHB Lake View Apartments, Sholinganallur, Chennai-600 119</p>
              </div>
            </div>

            <div className="contact-detail slide-in-right" style={{ transitionDelay: '0.1s' }}>
              <div className="contact-icon">
                <i className="fas fa-map-marker-alt"></i>
              </div>
              <div>
                <h4>Residence Address</h4>
                <p>28 G1, Vasanth Apartments, Thiruveethi Amman Koil Street, Kalikundram, Taramani - Post, Chennai-600 113</p>
              </div>
            </div>

            <div className="contact-detail slide-in-right" style={{ transitionDelay: '0.2s' }}>
              <div className="contact-icon">
                <i className="fas fa-phone"></i>
              </div>
              <div>
                <h4>Phone Numbers</h4>
                <p>
                  9840546117 / 7845946117
                  <br />
                  10:00 AM - 5:00 PM
                </p>
              </div>
            </div>

            <div className="contact-detail slide-in-right" style={{ transitionDelay: '0.3s' }}>
              <div className="contact-icon">
                <i className="fas fa-envelope"></i>
              </div>
              <div>
                <h4>Email Addresses</h4>
                <p>
                  info@victorytoday.edu
                  <br />
                  tnoucuiic@gmail.com
                </p>
              </div>
            </div>

            <div className="contact-detail slide-in-right" style={{ transitionDelay: '0.4s' }}>
              <div className="contact-icon">
                <i className="fab fa-whatsapp"></i>
              </div>
              <div>
                <h4>WhatsApp</h4>
                <p>9445430215</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  )
}

export default Contact
