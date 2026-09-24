import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { useSession } from '../context/SessionContext'
import Header from '../components/home/Header'

function ReaderPage() {
  const { bookId } = useParams()
  const { state } = useSession()
  const book = state.books.find(b => b.id === bookId)
  const order = state.orders.find(
    o => o.bookId === bookId && o.purchaseType === 'digital' && o.paymentStatus === 'paid' && o.customerEmail === state.customerEmail
  )
  const [page, setPage] = useState(0)

  useEffect(() => {
    const blockContextMenu = (e) => e.preventDefault()
    document.addEventListener('contextmenu', blockContextMenu)
    return () => document.removeEventListener('contextmenu', blockContextMenu)
  }, [])

  if (!book) return <><Header /><div className="container">Book not found.</div></>
  if (!order) return <><Header /><div className="container">You don't have access to this book. Please purchase it first.</div></>

  const totalPages = book.pageImages.length

  return (
    <>
      <Header />
      <div className="container reader" style={{ userSelect: 'none', textAlign: 'center' }}>
        <div className="reader-watermark">Licensed to {state.customerEmail} — Order {order.id}</div>
        <img src={book.pageImages[page]} alt={`Page ${page + 1}`} style={{ maxWidth: '100%' }} />
        <div className="reader-controls">
          <button disabled={page === 0} onClick={() => setPage(p => p - 1)}>Previous</button>
          <span>Page {page + 1} of {totalPages}</span>
          <button disabled={page === totalPages - 1} onClick={() => setPage(p => p + 1)}>Next</button>
        </div>
      </div>
    </>
  )
}

export default ReaderPage
