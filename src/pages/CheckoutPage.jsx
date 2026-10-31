import { useState, useEffect, useRef } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { useSession } from '../context/SessionContext'
import { fetchBook } from '../api/books'
import { fetchQuote, createOrder, verifyPayment } from '../api/orders'
import { loadRazorpay } from '../utils/razorpay'
import { getStoredReferral } from '../utils/referral'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'
import PriceBreakdown from '../components/checkout/PriceBreakdown'
import AddressForm from '../components/checkout/AddressForm'
import ReferralCodeInput from '../components/checkout/ReferralCodeInput'

const EMPTY_ADDRESS = { fullName: '', phone: '', addressLine: '', city: '', district: '', state: '', pincode: '' }

function Shell({ children }) {
  return <><Header /><div className="container">{children}</div><Footer /></>
}

function CheckoutPage() {
  const { bookId } = useParams()
  const navigate = useNavigate()
  const { state } = useSession()
  const token = localStorage.getItem('vtee-auth-token')

  const [book, setBook] = useState(null)
  const [bookStatus, setBookStatus] = useState('loading') // 'loading' | 'ready' | 'missing'
  const [purchaseType, setPurchaseType] = useState('digital')
  const [quote, setQuote] = useState(null)
  const [address, setAddress] = useState(EMPTY_ADDRESS)
  const [fieldErrors, setFieldErrors] = useState({})
  const [referral, setReferral] = useState(() => getStoredReferral())
  const [message, setMessage] = useState('')
  const [submitting, setSubmitting] = useState(false)
  // The order created for this form's current contents; reused on retry so we never create a second one.
  const createdRef = useRef(null)
  const busyRef = useRef(false)

  useEffect(() => {
    let cancelled = false
    fetchBook(bookId)
      .then(data => { if (!cancelled) { setBook(data); setBookStatus('ready') } })
      .catch(() => { if (!cancelled) setBookStatus('missing') })
    return () => { cancelled = true }
  }, [bookId])

  useEffect(() => {
    let cancelled = false
    setQuote(null)
    fetchQuote(bookId, purchaseType)
      .then(q => { if (!cancelled) setQuote(q) })
      .catch(err => { if (!cancelled) setMessage(err.message) })
    return () => { cancelled = true }
  }, [bookId, purchaseType])

  // Any edit invalidates the order that was created from the old values.
  const edit = (setter) => (value) => { createdRef.current = null; setter(value) }
  const changeType = edit(setPurchaseType)
  const changeAddress = edit(setAddress)
  const changeReferral = edit(setReferral)

  if (bookStatus === 'loading') return <Shell>Loading…</Shell>
  if (bookStatus === 'missing') return <Shell>Book not found.</Shell>
  if (!token) return <Shell><p><Link to="/account">Log in to continue</Link></p></Shell>

  const openCheckout = async ({ order, razorpay }) => {
    const Razorpay = await loadRazorpay()
    const rzp = new Razorpay({
      key: razorpay.keyId,
      amount: razorpay.amount,
      currency: razorpay.currency,
      order_id: razorpay.orderId,
      name: 'VTEE',
      prefill: { email: state.customerEmail || undefined },
      // Access is never granted here: the server verifies the signature; the result page shows the outcome.
      handler: async (resp) => {
        try {
          await verifyPayment(token, order.id, resp)
        } catch {
          // ponytail: result page polls the order, so a failed verify call is not fatal here.
        }
        navigate(`/orders/${order.id}/result`)
      },
      modal: {
        ondismiss: () => {
          setMessage('Payment cancelled. You can try again.')
          busyRef.current = false
          setSubmitting(false)
        },
      },
    })
    rzp.on('payment.failed', (resp) => {
      setMessage(resp?.error?.description || 'Payment failed. You can try again.')
      busyRef.current = false
      setSubmitting(false)
    })
    rzp.open()
  }

  const handlePay = async (e) => {
    e.preventDefault()
    if (busyRef.current) return
    busyRef.current = true
    setSubmitting(true)
    setMessage('')
    setFieldErrors({})
    try {
      if (!createdRef.current) {
        const hardcopy = purchaseType === 'hardcopy'
        createdRef.current = await createOrder(token, {
          bookId,
          purchaseType,
          referralCode: referral.trim() || undefined,
          ...(hardcopy && { address }),
        })
      }
      await openCheckout(createdRef.current)
    } catch (err) {
      setMessage(err.message)
      if (err.fields) {
        setFieldErrors(Object.fromEntries(Object.entries(err.fields).map(([k, v]) => [k.replace(/^address\./, ''), v])))
      }
      busyRef.current = false
      setSubmitting(false)
    }
  }

  return (
    <>
      <Header />
      <section className="container" style={{ paddingTop: '2rem', paddingBottom: '2rem' }}>
        <h2>Checkout: {book.title}</h2>

        <form onSubmit={handlePay}>
          <fieldset>
            <legend>Purchase type</legend>
            <label>
              <input type="radio" name="purchaseType" value="digital" checked={purchaseType === 'digital'} onChange={() => changeType('digital')} />
              Digital (₹{book.onlinePrice})
            </label>
            <label>
              <input type="radio" name="purchaseType" value="hardcopy" checked={purchaseType === 'hardcopy'} onChange={() => changeType('hardcopy')} />
              Hard Copy (₹{book.hardcopyPrice})
            </label>
          </fieldset>

          {purchaseType === 'hardcopy' && <AddressForm value={address} onChange={changeAddress} errors={fieldErrors} />}

          <ReferralCodeInput value={referral} onChange={changeReferral} />

          {quote && <PriceBreakdown quote={quote} />}

          <p aria-live="polite" className="form-error">{message}</p>
          <button type="submit" className="btn btn-primary" disabled={submitting || !quote}>Pay with Razorpay</button>
        </form>
      </section>
      <Footer />
    </>
  )
}

export default CheckoutPage
