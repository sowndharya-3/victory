import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useSession } from '../context/SessionContext'
import { registerCustomer, loginCustomer } from '../api/auth'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'

function AccountPage() {
  const { state, dispatch } = useSession()
  const [mode, setMode] = useState('login') // 'login' | 'register'
  const [form, setForm] = useState({ name: '', email: '', password: '' })
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const update = (field) => (e) => setForm(f => ({ ...f, [field]: e.target.value }))

  const handleSubmit = async (e) => {
    e.preventDefault()
    setError('')
    setSubmitting(true)
    try {
      const action = mode === 'login' ? loginCustomer : registerCustomer
      const result = await action(form)
      localStorage.setItem('vtee-auth-token', result.token)
      // Keep the existing mock purchase/order flow working unchanged —
      // AccountPage's order list and every other page's purchased-gate
      // still key off SessionContext.customerEmail, not the real backend.
      dispatch({ type: 'LOGIN_CUSTOMER', payload: { email: result.user.email } })
    } catch (err) {
      setError(err.message)
    } finally {
      setSubmitting(false)
    }
  }

  if (!state.customerEmail) {
    return (
      <>
        <Header />
        <div className="container">
          <h2>{mode === 'login' ? 'Log In' : 'Create Account'}</h2>
          {error && <p className="form-error">{error}</p>}
          <form onSubmit={handleSubmit}>
            {mode === 'register' && (
              <>
                <label htmlFor="account-name">Name</label>
                <input id="account-name" className="form-control" value={form.name} onChange={update('name')} required />
              </>
            )}
            <label htmlFor="account-email">Email</label>
            <input id="account-email" type="email" className="form-control" value={form.email} onChange={update('email')} required />
            <label htmlFor="account-password">Password</label>
            <input id="account-password" type="password" className="form-control" value={form.password} onChange={update('password')} required minLength={8} />
            <button type="submit" className="btn btn-primary" disabled={submitting}>
              {mode === 'login' ? 'Log In' : 'Create Account'}
            </button>
          </form>
          <button type="button" className="btn btn-outline" onClick={() => setMode(m => (m === 'login' ? 'register' : 'login'))}>
            {mode === 'login' ? 'Need an account? Register' : 'Already have an account? Log In'}
          </button>
        </div>
        <Footer />
      </>
    )
  }

  const myPaidOrders = state.orders.filter(o => o.customerEmail === state.customerEmail && o.paymentStatus === 'paid')

  return (
    <>
      <Header />
      <div className="container">
        <h2>My Books</h2>
        {myPaidOrders.length === 0 && <p>No purchased books yet.</p>}
        <ul>
          {myPaidOrders.map(order => {
            const book = state.books.find(b => b.id === order.bookId)
            return (
              <li key={order.id}>
                {book?.title} ({order.purchaseType})
                {order.purchaseType === 'digital' && <Link to={`/read/${order.bookId}`}> Read</Link>}
              </li>
            )
          })}
        </ul>
      </div>
      <Footer />
    </>
  )
}

export default AccountPage
