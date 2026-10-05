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
            <div key={book.id} className="book-card">
              <img src={book.coverUrl} alt={book.title} className="book-card-cover" />
              <div className="book-card-body">
                <h3 className="book-card-title">{book.title}</h3>
                <span className="book-card-type">{book.type}</span>
                <ul className="book-card-prices">
                  <li><span>Online Price</span><span className="price-value">₹{book.onlinePrice}</span></li>
                  <li><span>Hard Copy Price</span><span className="price-value">₹{book.hardCopyPrice}</span></li>
                </ul>
                <Link to={`/books/${book.id}`} className="btn btn-primary">View &amp; Purchase</Link>
              </div>
            </div>
          ))}
        </div>
      </section>
      <Footer />
    </>
  )
}

export default BooksPage
