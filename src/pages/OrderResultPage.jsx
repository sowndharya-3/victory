import { useEffect, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { fetchMyOrder } from '../api/orders'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'
import PriceBreakdown from '../components/checkout/PriceBreakdown'

const POLL_MS = 3000
const GIVE_UP_MS = 60000

function Shell({ children }) {
  return <><Header /><div className="container" style={{ padding: '2rem 0' }}>{children}</div><Footer /></>
}

function OrderResultPage() {
  const { orderId } = useParams()
  const token = localStorage.getItem('vtee-auth-token')
  const [order, setOrder] = useState(null)
  const [error, setError] = useState('')
  const [timedOut, setTimedOut] = useState(false)

  // Poll until the server says the order is no longer pending. The page only reports; it never grants access.
  useEffect(() => {
    if (!token) return undefined
    let cancelled = false
    let timer
    let waited = 0
    const poll = async () => {
      try {
        const o = await fetchMyOrder(token, orderId)
        if (cancelled) return
        setOrder(o)
        if (o.status !== 'pending') return
      } catch (err) {
        if (!cancelled) setError(err.message)
        return
      }
      if (waited >= GIVE_UP_MS) { setTimedOut(true); return }
      waited += POLL_MS
      timer = setTimeout(poll, POLL_MS)
    }
    poll()
    return () => { cancelled = true; clearTimeout(timer) }
  }, [orderId, token])

  if (!token) return <Shell><p><Link to="/account">Log in to see your order</Link></p></Shell>
  if (error) return <Shell><p role="alert" className="form-error">{error}</p></Shell>
  if (!order) return <Shell><p aria-live="polite">Checking your payment…</p></Shell>

  const hardcopy = order.purchaseType === 'hardcopy'

  if (order.status === 'paid') {
    return (
      <Shell>
        <div aria-live="polite">
          <h2>Payment received</h2>
          <p>Order <strong>{order.orderNumber}</strong>: {order.bookTitle}</p>
        </div>
        <PriceBreakdown quote={order} />
        {hardcopy ? (
          <p>We will ship to {order.address?.city}. We will email you when it ships.</p>
        ) : (
          <p>Your digital order is listed in <Link to="/account">your account</Link>.</p>
        )}
      </Shell>
    )
  }

  if (order.status === 'pending') {
    return (
      <Shell>
        <p aria-live="polite">
          {timedOut
            ? "We're still confirming your payment — you don't need to pay again; check My Orders shortly."
            : 'We are confirming your payment. This usually takes a few seconds…'}
        </p>
        {timedOut && <p><Link to="/account">Go to My Orders</Link></p>}
      </Shell>
    )
  }

  if (order.status === 'failed' || order.status === 'cancelled') {
    return (
      <Shell>
        <h2>Payment failed</h2>
        <p>Order {order.orderNumber} was not completed.</p>
        <p><Link to={`/checkout/${order.bookId}`}>Try again</Link></p>
      </Shell>
    )
  }

  return <Shell><p>Order {order.orderNumber}: {order.status}. See <Link to="/account">your account</Link>.</p></Shell>
}

export default OrderResultPage
