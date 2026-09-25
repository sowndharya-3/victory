import { useSession } from '../../context/SessionContext'

function AdminCustomersPage() {
  const { state } = useSession()
  const byCustomer = {}
  for (const order of state.orders) {
    if (!byCustomer[order.customerEmail]) byCustomer[order.customerEmail] = []
    byCustomer[order.customerEmail].push(order)
  }

  return (
    <div>
      <h2>Customers</h2>
      <table>
        <thead>
          <tr><th>Email</th><th>Orders</th><th>Total Spent (paid)</th></tr>
        </thead>
        <tbody>
          {Object.entries(byCustomer).map(([email, orders]) => (
            <tr key={email}>
              <td>{email}</td>
              <td>{orders.length}</td>
              <td>₹{orders.filter(o => o.paymentStatus === 'paid').reduce((s, o) => s + o.amount, 0)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export default AdminCustomersPage
