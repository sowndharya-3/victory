import { useEffect, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { fetchMyOrder, fetchAccessCode, redeemCode } from '../api/orders'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'
import PriceBreakdown from '../components/checkout/PriceBreakdown'

const POLL_MS = 3000
const GIVE_UP_MS = 60000

function Shell({ children }) {
  return <><Header /><div className="container" style={{ padding: '2rem 0' }}>{children}</div><Footer /></>
}

// Paying never unlocks the book: the owner redeems their code (shown here, never stored in the browser).
function DigitalCode({ token, order }) {
  const status = order.digitalCode?.status
  const [code, setCode] = useState('')
  const [message, setMessage] = useState('')
  const [redeemed, setRedeemed] = useState(false)

  useEffect(() => {
    if (status !== 'active') return undefined
    let cancelled = false
    fetchAccessCode(token, order.id)
      .then(r => { if (!cancelled) setCode(r.code) })
      .catch(err => { if (!cancelled) setMessage(err.message) })
    return () => { cancelled = true }
  }, [status, token, order.id])

  const redeem = async () => {
    setMessage('')
    try {
      await redeemCode(token, code)
      setCode('')
      setRedeemed(true)
    } catch (err) {
      setMessage(err.message)
    }
  }

  if (redeemed) return <p aria-live="polite">Added to My Books. <Link to="/my-books">Go to My Books</Link></p>
  if (status === 'redeemed') return <p>This book is already in your My Books. <Link to="/my-books">Go to My Books</Link></p>

  return (
    <section aria-label="Your access code">
      {code && (
        <>
          <p>Your access code:</p>
          <p><strong style={{ letterSpacing: '0.05em' }}>{code}</strong></p>
          <p>Payment alone does not unlock the book until you redeem this code. You can also find it later in My Books.</p>
          <button type="button" className="btn btn-primary" onClick={redeem}>Redeem to My Books</button>
        </>
      )}
      <p aria-live="polite" className="form-error">{message}</p>
    </section>
  )
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
          <p>We will ship to {order.address?.city}. Use your tracking code below to follow it.</p>
        ) : (
          <p>Your digital order is listed in <Link to="/account">your account</Link>.</p>
        )}
        {!hardcopy && <DigitalCode token={token} order={order} />}
        {hardcopy && order.trackingCode && (
          <p>Your tracking code: <strong>{order.trackingCode}</strong>. <Link to="/track">Track your order</Link></p>
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
