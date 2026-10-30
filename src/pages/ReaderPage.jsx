import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useSession } from '../context/SessionContext'
import { fetchAccess } from '../api/reader'
import { fetchBook } from '../api/books'
import Header from '../components/home/Header'
import ReaderViewer from '../components/reader/ReaderViewer'

function Shell({ children }) {
  return <><Header /><div className="container" style={{ padding: '2rem 0' }}>{children}</div></>
}

function ReaderPage() {
  const { bookId } = useParams()
  const { state } = useSession()
  const token = localStorage.getItem('vtee-auth-token')
  const [access, setAccess] = useState(null)
  const [book, setBook] = useState(null)
  const [loadError, setLoadError] = useState(null)

  useEffect(() => {
    if (!token) return undefined
    let cancelled = false
    // Owners keep reading unpublished books, so a failed public book lookup is not fatal.
    Promise.all([fetchAccess(token, bookId), fetchBook(bookId).catch(() => null)])
      .then(([a, b]) => { if (!cancelled) { setAccess(a); setBook(b) } })
      .catch(err => { if (!cancelled) setLoadError(err) })
    return () => { cancelled = true }
  }, [token, bookId])

  const notReady = <Shell><p>This book is not available to read yet. Please try again later.</p></Shell>
  if (!token || loadError?.status === 401) return <Shell><p><Link to="/account">Log in to read this book</Link></p></Shell>
  if (loadError?.status >= 500) return notReady // e.g. render_failed while counting pages
  if (loadError) return <Shell><p role="alert" className="form-error">{loadError.message}</p></Shell>
  if (!access) return <Shell><p aria-live="polite">Loading…</p></Shell>

  if (!access.owned) {
    return (
      <Shell>
        <p>You don't have access to this book{access.status === 'revoked' ? ' (access was revoked)' : ''}.</p>
        <p><Link to="/my-books">Go to My Books</Link> · <Link to={`/books/${bookId}`}>View this book</Link></p>
      </Shell>
    )
  }

  if (!access.pageCount) return notReady

  return (
    <Shell>
      {book?.title && <h2>{book.title}</h2>}
      <ReaderViewer token={token} bookId={bookId} pageCount={access.pageCount} book={book} email={state.customerEmail} />
    </Shell>
  )
}

export default ReaderPage
