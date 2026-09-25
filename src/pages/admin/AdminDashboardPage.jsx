import { useSession } from '../../context/SessionContext'

function AdminDashboardPage() {
  const { state } = useSession()
  const totalDigital = state.orders.filter(o => o.purchaseType === 'digital' && o.paymentStatus === 'paid').length
  const totalHardCopy = state.orders.filter(o => o.purchaseType === 'hardcopy' && o.paymentStatus === 'paid').length
  const revenue = state.orders.filter(o => o.paymentStatus === 'paid').reduce((sum, o) => sum + o.amount, 0)
  const commission = state.orders
    .filter(o => o.paymentStatus === 'paid' && o.referralPartnerId)
    .reduce((sum, o) => sum + o.commission, 0)

  return (
    <div>
      <h2>Dashboard</h2>
      <ul>
        <li>Total Books: {state.books.length}</li>
        <li>Total Digital Purchases: {totalDigital}</li>
        <li>Total Hard Copy Purchases: {totalHardCopy}</li>
        <li>Total Referral Partners: {state.referralPartners.length}</li>
        <li>Total Orders: {state.orders.length}</li>
        <li>Revenue: ₹{revenue}</li>
        <li>Commission Summary: ₹{commission}</li>
      </ul>
    </div>
  )
}

export default AdminDashboardPage
