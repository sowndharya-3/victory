import { useEffect, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { fetchBook } from '../api/books'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'

function BookDetailPage() {
  const { id } = useParams()
  const [book, setBook] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    setLoading(true)
    fetchBook(id)
      .then(data => {
        setBook({
          id: String(data.id),
          title: data.title,
          description: data.description,
          type: data.category,
          coverUrl: data.coverUrl,
          onlinePrice: data.onlinePrice,
          hardCopyPrice: data.hardcopyPrice,
        })
      })
      .catch(() => setBook(null))
      .finally(() => setLoading(false))
  }, [id])

  if (loading) return <><Header /><div className="container">Loading…</div><Footer /></>
  if (!book) return <><Header /><div className="container">Book not found.</div><Footer /></>

  return (
    <>
      <Header />
      <section className="container" style={{ paddingTop: '2rem', paddingBottom: '2rem' }}>
        <img src={book.coverUrl} alt={book.title} className="book-detail-cover" />
        <h2>{book.title}</h2>
        <p>{book.description}</p>
        <p>Type: {book.type}</p>
        <p>Online reading price: ₹{book.onlinePrice}</p>
        <p>Hard copy price: ₹{book.hardCopyPrice}</p>
        <Link to={`/checkout/${book.id}`} className="btn btn-primary">Purchase</Link>
      </section>
      <Footer />
    </>
  )
}

export default BookDetailPage
