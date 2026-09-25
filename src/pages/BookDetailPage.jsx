import { useParams, Link } from 'react-router-dom'
import { useSession } from '../context/SessionContext'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'

function BookDetailPage() {
  const { id } = useParams()
  const { state } = useSession()
  const book = state.books.find(b => b.id === id && b.published)

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
