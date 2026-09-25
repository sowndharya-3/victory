import { BrowserRouter, Routes, Route } from 'react-router-dom'
import { SessionProvider } from './context/SessionContext'
import { useScrollReveal } from './hooks/useScrollReveal'
import HomePage from './pages/HomePage'
import BooksPage from './pages/BooksPage'
import BookDetailPage from './pages/BookDetailPage'
import ReferralRegisterPage from './pages/ReferralRegisterPage'
import CheckoutPage from './pages/CheckoutPage'
import ReaderPage from './pages/ReaderPage'
import AccountPage from './pages/AccountPage'
import AdminLayout from './pages/admin/AdminLayout'
import AdminDashboardPage from './pages/admin/AdminDashboardPage'
import AdminBooksPage from './pages/admin/AdminBooksPage'
import AdminOrdersPage from './pages/admin/AdminOrdersPage'
import AdminCustomersPage from './pages/admin/AdminCustomersPage'
import AdminReferralsPage from './pages/admin/AdminReferralsPage'
import AdminCommissionsPage from './pages/admin/AdminCommissionsPage'

function App() {
  useScrollReveal()
  return (
    <SessionProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/" element={<HomePage />} />
          <Route path="/books" element={<BooksPage />} />
          <Route path="/books/:id" element={<BookDetailPage />} />
          <Route path="/referral/register" element={<ReferralRegisterPage />} />
          <Route path="/checkout/:bookId" element={<CheckoutPage />} />
          <Route path="/read/:bookId" element={<ReaderPage />} />
          <Route path="/account" element={<AccountPage />} />
          <Route path="/admin" element={<AdminLayout />}>
            <Route index element={<AdminDashboardPage />} />
            <Route path="dashboard" element={<AdminDashboardPage />} />
            <Route path="books" element={<AdminBooksPage />} />
            <Route path="orders" element={<AdminOrdersPage />} />
            <Route path="customers" element={<AdminCustomersPage />} />
            <Route path="referrals" element={<AdminReferralsPage />} />
            <Route path="commissions" element={<AdminCommissionsPage />} />
          </Route>
        </Routes>
      </BrowserRouter>
    </SessionProvider>
  )
}

export default App
