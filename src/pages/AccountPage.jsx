import { useState, useEffect } from 'react'
import { Link } from 'react-router-dom'
import { useSession } from '../context/SessionContext'
import { registerCustomer, loginCustomer } from '../api/auth'
import { fetchMyOrders } from '../api/orders'
import { fulfilmentLabel, money, shortDate } from '../utils/orderLabels'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'

function AccountPage() {
  const { state, dispatch } = useSession()
  const [mode, setMode] = useState('login') // 'login' | 'register'
  const [form, setForm] = useState({ name: '', email: '', password: '' })
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const [orders, setOrders] = useState(null) // null = loading
  const [ordersError, setOrdersError] = useState('')
  const loggedIn = Boolean(state.customerEmail)

  useEffect(() => {
    if (!loggedIn) return undefined
    let cancelled = false
    fetchMyOrders(localStorage.getItem('vtee-auth-token'))
      .then(list => { if (!cancelled) setOrders(list) })
      .catch(err => { if (!cancelled) setOrdersError(err.message) })
    return () => { cancelled = true }
  }, [loggedIn])

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

  return (
    <>
      <Header />
      <div className="container">
        <h2>My Orders</h2>
        <p><Link to="/my-books">My Books</Link> · <Link to="/track">Track an order</Link></p>
        <div aria-live="polite">
          {ordersError && <p className="form-error">{ordersError}</p>}
          {!ordersError && orders === null && <p>Loading orders…</p>}
          {orders?.length === 0 && <p>No orders yet.</p>}
        </div>
        <ul>
          {orders?.map(o => (
            <li key={o.id}>
              <strong>{o.bookTitle}</strong> ({o.purchaseType === 'hardcopy' ? 'Hard copy' : 'Digital'}) · {o.orderNumber} · {o.status}
              {o.purchaseType === 'hardcopy' && o.status === 'paid' && <> · {fulfilmentLabel(o.fulfilmentStatus)}</>}
              {' '}· {money(o.total)} · {shortDate(o.createdAt)}
            </li>
          ))}
        </ul>
      </div>
      <Footer />
    </>
  )
}

export default AccountPage
