import { BrowserRouter, Routes, Route } from 'react-router-dom'
import { SessionProvider } from './context/SessionContext'
import HomePage from './pages/HomePage'
import BooksPage from './pages/BooksPage'
import BookDetailPage from './pages/BookDetailPage'
import ReferralRegisterPage from './pages/ReferralRegisterPage'
import CheckoutPage from './pages/CheckoutPage'
import ReaderPage from './pages/ReaderPage'

function App() {
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
        </Routes>
      </BrowserRouter>
    </SessionProvider>
  )
}

export default App
