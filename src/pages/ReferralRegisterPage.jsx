import { useState } from 'react'
import { submitReferralApplication } from '../api/orders'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'

// [field, label, type, required] — optional ones match backend referral_applications nullable columns.
const FIELDS = [
  ['name', 'Name', 'text', true],
  ['qualification', 'Educational Qualification', 'text', false],
  ['place', 'Place', 'text', false],
  ['district', 'District', 'text', false],
  ['phone', 'Phone Number', 'tel', true],
  ['whatsapp', 'WhatsApp Number', 'tel', true],
  ['upiId', 'GPay / PhonePe details', 'text', false],
  ['gmail', 'Gmail ID', 'email', true],
]

const CODE_MESSAGES = {
  duplicate_application: 'You already have an application pending or approved with this Gmail ID.',
  rate_limited: 'Too many applications from your network. Please try again in an hour.',
}

function ReferralRegisterPage() {
  const [form, setForm] = useState({ name: '', qualification: '', place: '', district: '', status: 'Student', phone: '', whatsapp: '', upiId: '', gmail: '' })
  const [submitted, setSubmitted] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [fields, setFields] = useState({})

  const update = (field) => (e) => setForm(f => ({ ...f, [field]: e.target.value }))

  const handleSubmit = async (e) => {
    e.preventDefault()
    setBusy(true)
    setError('')
    setFields({})
    try {
      await submitReferralApplication(form)
      setSubmitted(true)
    } catch (err) {
      setFields(err.fields ?? {})
      setError(CODE_MESSAGES[err.code] || (err.fields ? 'Please correct the highlighted fields.' : err.message))
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <Header />
      <section className="container" style={{ padding: '2rem 0' }}>
        <h2>Become a Referral Partner</h2>
        {submitted ? (
          <p>Registration received. Our team will review and approve your account shortly.</p>
        ) : (
          <form onSubmit={handleSubmit}>
            {FIELDS.map(([field, label, type, required]) => (
              <div key={field}>
                <label htmlFor={field}>{label}</label>
                <input id={field} type={type} className="form-control" value={form[field]} onChange={update(field)} required={required}
                  aria-invalid={fields[field] ? true : undefined} aria-describedby={fields[field] ? `${field}-error` : undefined} />
                {fields[field] && <p id={`${field}-error`} className="form-error">{fields[field]}</p>}
              </div>
            ))}
            <label htmlFor="status">Status</label>
            <select id="status" className="form-control" value={form.status} onChange={update('status')}>
              <option>Student</option>
              <option>Scholarship</option>
              <option>Faculty</option>
              <option>Institution</option>
            </select>
            {fields.status && <p className="form-error">{fields.status}</p>}
            {error && <p role="alert" className="form-error">{error}</p>}
            <button type="submit" className="btn btn-primary" disabled={busy}>Register</button>
          </form>
        )}
      </section>
      <Footer />
    </>
  )
}

export default ReferralRegisterPage
