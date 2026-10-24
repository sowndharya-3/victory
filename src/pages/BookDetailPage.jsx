import { useEffect, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { useSession } from '../context/SessionContext'
import { fetchBook } from '../api/books'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'

function BookDetailPage() {
  const { id } = useParams()
  const { state } = useSession()
  // A match against the mock seed data (by id alone, published or not) means
  // this is a mock id — resolve synchronously, exactly as before, whether
  // that's the book itself or "not found" because it's unpublished. Only an
  // id with NO mock match at all (a real backend id) falls back to the API.
  const mockMatch = state.books.find(b => b.id === id)
  const isMockId = Boolean(mockMatch)
  const mockBook = mockMatch && mockMatch.published ? mockMatch : null

  const [remoteBook, setRemoteBook] = useState(null)
  const [loading, setLoading] = useState(!isMockId)

  useEffect(() => {
    if (isMockId) return
    setLoading(true)
    fetchBook(id)
      .then(data => {
        setRemoteBook({
          id: String(data.id),
          title: data.title,
          description: data.description,
          type: data.category,
          coverUrl: data.coverUrl,
          onlinePrice: data.onlinePrice,
          hardCopyPrice: data.hardcopyPrice,
        })
      })
      .catch(() => setRemoteBook(null))
      .finally(() => setLoading(false))
  }, [id, isMockId])

  const book = isMockId ? mockBook : remoteBook

  if (loading) return <><Header /><div className="container">Loading…</div><Footer /></>
  if (!book) return <><Header /><div className="container">Book not found.</div><Footer /></>

  const purchased = state.orders.some(
    o => o.bookId === book.id && o.paymentStatus === 'paid' && o.purchaseType === 'digital' && o.customerEmail === state.customerEmail
  )

  return (
    <>
      <Header />
      <section className="container" style={{ padding: '2rem 0' }}>
        <img src={book.coverUrl} alt={book.title} />
        <h2>{book.title}</h2>
        <p>{book.description}</p>
        <p>Type: {book.type}</p>
        <p>Online reading price: ₹{book.onlinePrice}</p>
        <p>Hard copy price: ₹{book.hardCopyPrice}</p>
        <Link to={`/checkout/${book.id}`} className="btn btn-primary">Purchase</Link>
        {purchased && <Link to={`/read/${book.id}`} className="btn btn-outline">Read Book</Link>}
      </section>
      <Footer />
    </>
  )
}

export default BookDetailPage
