import { Link, Outlet } from 'react-router-dom'
import { useSession } from '../../context/SessionContext'
import AdminLoginPage from './AdminLoginPage'

function AdminLayout() {
  const { state, dispatch } = useSession()

  if (!state.admin) return <AdminLoginPage />

  return (
    <div className="admin-layout">
      <nav>
        <Link to="/admin">Dashboard</Link>
        <Link to="/admin/books">Books</Link>
        <Link to="/admin/customers">Customers</Link>
        <Link to="/admin/referrals">Referral Partners</Link>
        <Link to="/admin/orders">Orders</Link>
        <Link to="/admin/commissions">Commissions</Link>
        <button onClick={() => dispatch({ type: 'LOGOUT_ADMIN' })}>Log Out</button>
      </nav>
      <main className="container">
        <Outlet />
      </main>
    </div>
  )
}

export default AdminLayout
