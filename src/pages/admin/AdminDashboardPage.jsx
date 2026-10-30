import { fetchDashboard, formatRupees } from '../../api/admin'
import { useLoad, LoadState } from './adminUi'

const CARDS = [
  ['publishedBooks', 'Published books'],
  ['totalCustomers', 'Customers'],
  ['digitalPurchases', 'Digital purchases'],
  ['hardcopyOrders', 'Hard copy orders'],
  ['successfulPayments', 'Successful payments'],
  ['pendingOrders', 'Hard copies to fulfil'],
  ['revenue', 'Revenue (net)', true],
  ['refundedAmount', 'Refunded', true],
  ['pendingRefunds', 'Open refunds'],
  ['commissionPending', 'Commission pending', true],
  ['commissionPayable', 'Commission payable', true],
]

function AdminDashboardPage() {
  const { data, error, loading } = useLoad(fetchDashboard, [])

  return (
    <div>
      <h2>Dashboard</h2>
      <LoadState loading={loading} error={error} />
      {data && (
        <ul className="admin-cards">
          {CARDS.map(([key, text, money]) => (
            <li key={key}>
              <span>{text}</span>
              <strong>{money ? formatRupees(data[key]) : data[key] ?? 0}</strong>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

export default AdminDashboardPage
