import { useState } from 'react'
import { useSession } from '../context/SessionContext'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'

const FIELDS = [
  ['name', 'Name', 'text'],
  ['qualification', 'Educational Qualification', 'text'],
  ['place', 'Place', 'text'],
  ['district', 'District', 'text'],
  ['phone', 'Phone Number', 'tel'],
  ['whatsapp', 'WhatsApp Number', 'tel'],
  ['upiId', 'GPay / PhonePe details', 'text'],
  ['gmail', 'Gmail ID', 'email'],
]

function ReferralRegisterPage() {
  const { dispatch } = useSession()
  const [form, setForm] = useState({ name: '', qualification: '', place: '', district: '', status: 'Student', phone: '', whatsapp: '', upiId: '', gmail: '' })
  const [submitted, setSubmitted] = useState(false)

  const update = (field) => (e) => setForm(f => ({ ...f, [field]: e.target.value }))

  const handleSubmit = (e) => {
    e.preventDefault()
    dispatch({ type: 'REGISTER_REFERRAL', payload: form })
    setSubmitted(true)
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
            {FIELDS.map(([field, label, type]) => (
              <div key={field}>
                <label htmlFor={field}>{label}</label>
                <input id={field} type={type} className="form-control" value={form[field]} onChange={update(field)} required />
              </div>
            ))}
            <label htmlFor="status">Status</label>
            <select id="status" className="form-control" value={form.status} onChange={update('status')}>
              <option>Student</option>
              <option>Scholarship</option>
              <option>Faculty</option>
              <option>Institution</option>
            </select>
            <button type="submit" className="btn btn-primary">Register</button>
          </form>
        )}
      </section>
      <Footer />
    </>
  )
}

export default ReferralRegisterPage
