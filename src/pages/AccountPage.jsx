import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useSession } from '../context/SessionContext'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'

function AccountPage() {
  const { state, dispatch } = useSession()
  const [emailInput, setEmailInput] = useState('')

  if (!state.customerEmail) {
    return (
      <>
        <Header />
        <div className="container">
          <label htmlFor="account-email">Email</label>
          <input id="account-email" type="email" className="form-control" value={emailInput} onChange={e => setEmailInput(e.target.value)} />
          <button className="btn btn-primary" onClick={() => dispatch({ type: 'LOGIN_CUSTOMER', payload: { email: emailInput } })}>
            View My Books
          </button>
        </div>
        <Footer />
      </>
    )
  }

  const myPaidOrders = state.orders.filter(o => o.customerEmail === state.customerEmail && o.paymentStatus === 'paid')

  return (
    <>
      <Header />
      <div className="container">
        <h2>My Books</h2>
        {myPaidOrders.length === 0 && <p>No purchased books yet.</p>}
        <ul>
          {myPaidOrders.map(order => {
            const book = state.books.find(b => b.id === order.bookId)
            return (
              <li key={order.id}>
                {book?.title} ({order.purchaseType})
                {order.purchaseType === 'digital' && <Link to={`/read/${order.bookId}`}> Read</Link>}
              </li>
            )
          })}
        </ul>
      </div>
      <Footer />
    </>
  )
}

export default AccountPage
