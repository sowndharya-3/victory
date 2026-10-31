import { useEffect } from 'react'
import { BrowserRouter, Routes, Route, Link } from 'react-router-dom'
import { captureReferralFromUrl } from './utils/referral'
import { SessionProvider } from './context/SessionContext'
import { useScrollReveal } from './hooks/useScrollReveal'
import HomePage from './pages/HomePage'
import BooksPage from './pages/BooksPage'
import BookDetailPage from './pages/BookDetailPage'
import ReferralRegisterPage from './pages/ReferralRegisterPage'
import CheckoutPage from './pages/CheckoutPage'
import OrderResultPage from './pages/OrderResultPage'
import MyBooksPage from './pages/MyBooksPage'
import TrackOrderPage from './pages/TrackOrderPage'
import ReaderPage from './pages/ReaderPage'
import AccountPage from './pages/AccountPage'
import AdminLayout from './pages/admin/AdminLayout'
import AdminDashboardPage from './pages/admin/AdminDashboardPage'
import AdminBooksPage from './pages/admin/AdminBooksPage'
import AdminOrdersPage from './pages/admin/AdminOrdersPage'
import AdminCustomersPage from './pages/admin/AdminCustomersPage'
import AdminReferralsPage from './pages/admin/AdminReferralsPage'
import AdminCommissionsPage from './pages/admin/AdminCommissionsPage'
import AdminRefundsPage from './pages/admin/AdminRefundsPage'
import AdminSettingsPage from './pages/admin/AdminSettingsPage'
import Header from './components/home/Header'
import Footer from './components/home/Footer'

function ScrollReveal() {
  useScrollReveal()
  return null
}

function ReferralCapture() {
  useEffect(() => {
    captureReferralFromUrl()
  }, [])
  return null
}

// Unknown paths: the host serves index.html for every route (public/.htaccess, public/_redirects),
// so "not found" is decided here, client-side, with HTTP 200.
function NotFound() {
  return <><Header /><div className="container" style={{ paddingTop: '2rem', paddingBottom: '2rem' }}><h2>Page not found</h2><p><Link to="/">Go to the home page</Link></p></div><Footer /></>
}

function App() {
  return (
    <SessionProvider>
      <BrowserRouter>
        <ScrollReveal />
        <ReferralCapture />
        <Routes>
          <Route path="/" element={<HomePage />} />
          <Route path="/books" element={<BooksPage />} />
          <Route path="/books/:id" element={<BookDetailPage />} />
          <Route path="/referral/register" element={<ReferralRegisterPage />} />
          <Route path="/checkout/:bookId" element={<CheckoutPage />} />
          <Route path="/orders/:orderId/result" element={<OrderResultPage />} />
          <Route path="/my-books" element={<MyBooksPage />} />
          <Route path="/track" element={<TrackOrderPage />} />
          <Route path="/read/:bookId" element={<ReaderPage />} />
          <Route path="/account" element={<AccountPage />} />
          <Route path="/admin" element={<AdminLayout />}>
            <Route index element={<AdminDashboardPage />} />
            <Route path="dashboard" element={<AdminDashboardPage />} />
            <Route path="books" element={<AdminBooksPage />} />
            <Route path="orders" element={<AdminOrdersPage />} />
            <Route path="customers" element={<AdminCustomersPage />} />
            <Route path="referrals" element={<AdminReferralsPage />} />
            <Route path="refunds" element={<AdminRefundsPage />} />
            <Route path="commissions" element={<AdminCommissionsPage />} />
            <Route path="settings" element={<AdminSettingsPage />} />
          </Route>
          <Route path="*" element={<NotFound />} />
        </Routes>
      </BrowserRouter>
    </SessionProvider>
  )
}

export default App
