import { useState, useEffect, useCallback } from 'react'
import { Link } from 'react-router-dom'
import { fetchLibrary, fetchMyOrders, redeemCode, fetchAccessCode } from '../api/orders'
import { fulfilmentLabel, shortDate } from '../utils/orderLabels'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'

function Shell({ children }) {
  return <><Header /><div className="container" style={{ paddingTop: '2rem', paddingBottom: '2rem' }}>{children}</div><Footer /></>
}

function Cover({ book }) {
  return book.coverUrl ? <img src={book.coverUrl} alt={book.title} style={{ width: 60, verticalAlign: 'middle', marginRight: 8 }} /> : null
}

function MyBooksPage() {
  const token = localStorage.getItem('vtee-auth-token')
  const [library, setLibrary] = useState(null) // { books, pendingRedemptions }
  const [orders, setOrders] = useState([])
  const [loadError, setLoadError] = useState('')
  const [input, setInput] = useState('')
  const [redeemMessage, setRedeemMessage] = useState('')
  // Per pending order: { code, message }. Codes live in memory only and are never persisted.
  const [reveals, setReveals] = useState({})

  const reload = useCallback(() => fetchLibrary(token).then(setLibrary), [token])

  useEffect(() => {
    if (!token) return undefined
    let cancelled = false
    Promise.all([fetchLibrary(token), fetchMyOrders(token)])
      .then(([lib, list]) => { if (!cancelled) { setLibrary(lib); setOrders(list) } })
      .catch(err => { if (!cancelled) setLoadError(err.message) })
    return () => { cancelled = true }
  }, [token])

  if (!token) return <Shell><p><Link to="/account">Log in to see your books</Link></p></Shell>
  if (loadError) return <Shell><p role="alert" className="form-error">{loadError}</p></Shell>
  if (!library) return <Shell><p aria-live="polite">Loading your books…</p></Shell>

  const patchReveal = (orderId, patch) => setReveals(r => ({ ...r, [orderId]: { ...r[orderId], ...patch } }))

  // Shared by the manual form and the "To redeem" rows; the server message is shown verbatim.
  const redeem = async (code, onError, onDone) => {
    try {
      await redeemCode(token, code)
      await reload()
      onDone()
    } catch (err) {
      onError(err.message)
    }
  }

  const submitCode = (e) => {
    e.preventDefault()
    setRedeemMessage('')
    redeem(input.trim(), setRedeemMessage, () => { setInput(''); setRedeemMessage('Added to My Books.') })
  }

  const showCode = async (orderId) => {
    try {
      const { code } = await fetchAccessCode(token, orderId)
      patchReveal(orderId, { code, message: '' })
    } catch (err) {
      patchReveal(orderId, { message: err.message })
    }
  }

  const hardCopies = orders.filter(o => o.purchaseType === 'hardcopy' && o.status === 'paid')

  return (
    <Shell>
      <h2>My Books</h2>
      {library.books.length === 0 && <p>You have no books yet.</p>}
      <ul>
        {library.books.map(b => (
          <li key={`${b.bookId}-${b.orderId}`}>
            <Cover book={b} />
            <strong>{b.title}</strong> · Purchased {shortDate(b.purchasedAt)} · Access: {b.accessStatus === 'active' ? 'Active' : 'Revoked'}
            {b.accessStatus === 'active' && <> · <Link to={`/read/${b.bookId}`}>Read Book</Link></>}
          </li>
        ))}
      </ul>

      <h3>Redeem a code</h3>
      <form onSubmit={submitCode}>
        <label htmlFor="redeem-code">Access code</label>
        <input id="redeem-code" className="form-control" value={input} onChange={e => setInput(e.target.value)} autoComplete="off" required />
        <button type="submit" className="btn btn-primary">Redeem</button>
      </form>
      <p aria-live="polite" className="form-error">{redeemMessage}</p>

      {library.pendingRedemptions.length > 0 && (
        <>
          <h3>To redeem</h3>
          <ul>
            {library.pendingRedemptions.map(p => {
              const r = reveals[p.orderId] || {}
              return (
                <li key={p.orderId}>
                  <Cover book={p} />
                  <strong>{p.title}</strong> · Purchased {shortDate(p.purchasedAt)}
                  {r.code ? (
                    <>
                      {' '}· Code: <strong>{r.code}</strong>{' '}
                      <button type="button" className="btn btn-primary"
                        onClick={() => redeem(r.code, m => patchReveal(p.orderId, { message: m }), () => patchReveal(p.orderId, { code: undefined }))}>
                        Redeem
                      </button>
                    </>
                  ) : (
                    <> <button type="button" className="btn btn-outline" onClick={() => showCode(p.orderId)}>Show my code</button></>
                  )}
                  <span aria-live="polite" className="form-error">{r.message}</span>
                </li>
              )
            })}
          </ul>
        </>
      )}

      {hardCopies.length > 0 && (
        <>
          <h3>Hard-copy orders</h3>
          <ul>
            {hardCopies.map(o => (
              <li key={o.id}>
                {o.orderNumber} · {o.bookTitle} · Tracking code: {o.trackingCode} · {fulfilmentLabel(o.fulfilmentStatus)}
              </li>
            ))}
          </ul>
          <p><Link to="/track">Track an order</Link></p>
        </>
      )}
    </Shell>
  )
}

export default MyBooksPage
