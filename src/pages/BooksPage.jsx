import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { fetchBooks } from '../api/books'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'

function BooksPage() {
  const [books, setBooks] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    fetchBooks()
      .then(setBooks)
      .catch(err => setError(err.message))
      .finally(() => setLoading(false))
  }, [])

  return (
    <>
      <Header />
      <section className="container" style={{ padding: '2rem 0' }}>
        <h2>Books</h2>
        {loading && <p>Loading books…</p>}
        {error && <p className="form-error">{error}</p>}
        <div className="books-grid">
          {books.map(book => (
            <div key={book.id} className="book-card">
              <img src={book.coverUrl} alt={book.title} className="book-card-cover" />
              <div className="book-card-body">
                <h3 className="book-card-title">{book.title}</h3>
                <span className="book-card-type">{book.category}</span>
                <ul className="book-card-prices">
                  <li><span>Online Price</span><span className="price-value">₹{book.onlinePrice}</span></li>
                  <li><span>Hard Copy Price</span><span className="price-value">₹{book.hardcopyPrice}</span></li>
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
