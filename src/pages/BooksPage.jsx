import { Link } from 'react-router-dom'
import { useSession } from '../context/SessionContext'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'

function BooksPage() {
  const { state } = useSession()
  const published = state.books.filter(b => b.published)

  return (
    <>
      <Header />
      <section className="container" style={{ padding: '2rem 0' }}>
        <h2>Books</h2>
        <div className="books-grid">
          {published.map(book => (
            <Link key={book.id} to={`/books/${book.id}`} className="book-card">
              <img src={book.coverUrl} alt={book.title} />
              <h3>{book.title}</h3>
              <p>{book.type}</p>
              <p>Online: ₹{book.onlinePrice} | Hard Copy: ₹{book.hardCopyPrice}</p>
            </Link>
          ))}
        </div>
      </section>
      <Footer />
    </>
  )
}

export default BooksPage
