import { useState, useEffect } from 'react'
import { useParams, Link } from 'react-router-dom'
import { useSession } from '../context/SessionContext'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'

function CheckoutPage() {
  const { bookId } = useParams()
  const { state, dispatch } = useSession()
  const book = state.books.find(b => b.id === bookId)
  const activePartners = state.referralPartners.filter(p => p.approved && p.active)

  const [email, setEmail] = useState('')
  const [purchaseType, setPurchaseType] = useState('digital')
  const [referralPartnerId, setReferralPartnerId] = useState('')
  const [paid, setPaid] = useState(false)
  const [awaitingPayment, setAwaitingPayment] = useState(false)

  // CREATE_ORDER assigns the order id internally, so we can't get it back
  // synchronously from dispatch. Wait for the new order to land in state,
  // then mark it paid. ponytail: matches by "last order in the list" since
  // CREATE_ORDER always appends; revisit if orders can be created elsewhere concurrently.
  useEffect(() => {
    if (!awaitingPayment) return
    const lastOrder = state.orders[state.orders.length - 1]
    if (lastOrder && lastOrder.paymentStatus === 'pending') {
      dispatch({ type: 'MARK_ORDER_PAID', payload: { orderId: lastOrder.id } })
      setPaid(true)
      setAwaitingPayment(false)
    }
  }, [state.orders, awaitingPayment, dispatch])

  if (!book) return <><Header /><div className="container">Book not found.</div><Footer /></>

  const amount = purchaseType === 'digital' ? book.onlinePrice : book.hardCopyPrice

  const handlePay = () => {
    if (!email) return
    dispatch({ type: 'LOGIN_CUSTOMER', payload: { email } })
    dispatch({
      type: 'CREATE_ORDER',
      payload: { bookId: book.id, customerEmail: email, purchaseType, amount, referralPartnerId: referralPartnerId || null },
    })
    // Real Razorpay checkout + server-side verification is added in Phase 2.
    // For now, mark the order paid immediately to unblock the reader/account flows.
    setAwaitingPayment(true)
  }

  if (paid) {
    return (
      <>
        <Header />
        <div className="container">
          <p>Payment successful. Your order for "{book.title}" is confirmed.</p>
          <Link to={`/books/${book.id}`} className="btn btn-primary">Back to book</Link>
        </div>
        <Footer />
      </>
    )
  }

  return (
    <>
      <Header />
      <section className="container" style={{ padding: '2rem 0' }}>
        <h2>Checkout: {book.title}</h2>

        <label htmlFor="email">Email</label>
        <input id="email" type="email" className="form-control" value={email} onChange={e => setEmail(e.target.value)} required />

        <fieldset>
          <legend>Purchase type</legend>
          <label>
            <input type="radio" name="purchaseType" value="digital" checked={purchaseType === 'digital'} onChange={() => setPurchaseType('digital')} />
            Digital (₹{book.onlinePrice})
          </label>
          <label>
            <input type="radio" name="purchaseType" value="hardcopy" checked={purchaseType === 'hardcopy'} onChange={() => setPurchaseType('hardcopy')} />
            Hard Copy (₹{book.hardCopyPrice})
          </label>
        </fieldset>

        <label htmlFor="referralPartner">Referral Partner (optional)</label>
        <select id="referralPartner" className="form-control" value={referralPartnerId} onChange={e => setReferralPartnerId(e.target.value)}>
          <option value="">None</option>
          {activePartners.map(p => (
            <option key={p.id} value={p.id}>{p.name}</option>
          ))}
        </select>

        <p>Total: ₹{amount}</p>
        <button className="btn btn-primary" onClick={handlePay}>Pay with Razorpay</button>
      </section>
      <Footer />
    </>
  )
}

export default CheckoutPage
