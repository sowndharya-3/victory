import { useEffect } from 'react'
import { Link, Outlet } from 'react-router-dom'
import { ADMIN_TOKEN_KEY, ADMIN_UNAUTHORIZED } from '../../api/admin'
import { useSession } from '../../context/SessionContext'
import AdminLoginPage from './AdminLoginPage'

function AdminLayout() {
  const { state, dispatch } = useSession()

  // Any admin call answered 401/403 clears the token (api/admin.js) and lands here: back to login.
  useEffect(() => {
    const onUnauthorized = () => dispatch({ type: 'LOGOUT_ADMIN' })
    window.addEventListener(ADMIN_UNAUTHORIZED, onUnauthorized)
    return () => window.removeEventListener(ADMIN_UNAUTHORIZED, onUnauthorized)
  }, [dispatch])

  if (!state.admin || !localStorage.getItem(ADMIN_TOKEN_KEY)) return <AdminLoginPage />

  return (
    <div className="admin-layout">
      <nav>
        <Link to="/admin">Dashboard</Link>
        <Link to="/admin/books">Books</Link>
        <Link to="/admin/customers">Customers</Link>
        <Link to="/admin/referrals">Referral Partners</Link>
        <Link to="/admin/orders">Orders</Link>
        <Link to="/admin/refunds">Refunds</Link>
        <Link to="/admin/commissions">Commissions</Link>
        <Link to="/admin/settings">Settings</Link>
        <button
          onClick={() => {
            localStorage.removeItem(ADMIN_TOKEN_KEY)
            dispatch({ type: 'LOGOUT_ADMIN' })
          }}
        >
          Log Out
        </button>
      </nav>
      <main className="container">
        <Outlet />
      </main>
    </div>
  )
}

export default AdminLayout
