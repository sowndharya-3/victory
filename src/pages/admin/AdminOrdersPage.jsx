import { useState } from 'react'
import { useSession } from '../../context/SessionContext'

function AdminOrdersPage() {
  const { state } = useSession()
  const [typeFilter, setTypeFilter] = useState('all')

  const filtered = state.orders.filter(o => typeFilter === 'all' || o.purchaseType === typeFilter)

  return (
    <div>
      <h2>Orders</h2>
      <label htmlFor="type-filter">Filter by type</label>
      <select id="type-filter" value={typeFilter} onChange={e => setTypeFilter(e.target.value)}>
        <option value="all">All</option>
        <option value="digital">Digital</option>
        <option value="hardcopy">Hard Copy</option>
      </select>

      <table>
        <thead>
          <tr><th>Order</th><th>Book</th><th>Customer</th><th>Type</th><th>Status</th><th>Amount</th><th>Referral</th></tr>
        </thead>
        <tbody>
          {filtered.map(order => {
            const book = state.books.find(b => b.id === order.bookId)
            const partner = state.referralPartners.find(p => p.id === order.referralPartnerId)
            return (
              <tr key={order.id}>
                <td>{order.id}</td>
                <td>{book?.title}</td>
                <td>{order.customerEmail}</td>
                <td>{order.purchaseType}</td>
                <td>{order.paymentStatus}</td>
                <td>₹{order.amount}</td>
                <td>{partner?.name ?? '—'}</td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

export default AdminOrdersPage
