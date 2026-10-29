import { useState } from 'react'
import { trackOrder } from '../api/orders'
import { shortDate } from '../utils/orderLabels'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'

const NOT_FOUND = 'Tracking code not found'

// Public page: the API returns status only (no address, email or digital codes).
function TrackOrderPage() {
  const [code, setCode] = useState('')
  const [result, setResult] = useState(null)
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState(false)

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true)
    setMessage('')
    setResult(null)
    try {
      setResult(await trackOrder(code.trim().toUpperCase()))
    } catch (err) {
      setMessage(err.message === NOT_FOUND ? "We couldn't find an order with that tracking code. Check the code and try again." : err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <Header />
      <div className="container" style={{ padding: '2rem 0' }}>
        <h2>Track your order</h2>
        <form onSubmit={submit}>
          <label htmlFor="track-code">Tracking code</label>
          <input id="track-code" className="form-control" value={code} onChange={e => setCode(e.target.value)} placeholder="VTT-XXXXXXXX" autoComplete="off" required />
          <button type="submit" className="btn btn-primary" disabled={busy}>Track</button>
        </form>
        <div aria-live="polite">
          {message && <p className="form-error">{message}</p>}
          {result && (
            <dl aria-label="Order status">
              <dt>Order</dt><dd>{result.orderNumber} · {result.bookTitle}</dd>
              <dt>Status</dt><dd>{result.label}</dd>
              {result.courier && <><dt>Courier</dt><dd>{result.courier}</dd></>}
              {result.trackingNumber && <><dt>Tracking number</dt><dd>{result.trackingNumber}</dd></>}
              <dt>Last updated</dt><dd>{shortDate(result.updatedAt)}</dd>
            </dl>
          )}
        </div>
      </div>
      <Footer />
    </>
  )
}

export default TrackOrderPage
