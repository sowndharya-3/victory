import { render, screen, fireEvent } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { SessionProvider } from '../context/SessionContext'
import CheckoutPage from './CheckoutPage'
import BookDetailPage from './BookDetailPage'

function renderAt(path) {
  return render(
    <SessionProvider>
      <MemoryRouter initialEntries={[path]}>
        <Routes><Route path="/checkout/:bookId" element={<CheckoutPage />} /></Routes>
      </MemoryRouter>
    </SessionProvider>
  )
}

test('only lists approved+active referral partners', () => {
  renderAt('/checkout/b1')
  expect(screen.getByRole('option', { name: /kavitha r/i })).toBeInTheDocument()
  expect(screen.queryByRole('option', { name: /arun s/i })).not.toBeInTheDocument()
})

test('completing mock payment shows confirmation', () => {
  renderAt('/checkout/b1')
  fireEvent.change(screen.getByLabelText(/email/i), { target: { value: 'buyer@x.com' } })
  fireEvent.click(screen.getByLabelText(/digital/i))
  fireEvent.click(screen.getByRole('button', { name: /pay with razorpay/i }))
  expect(screen.getByText(/payment successful/i)).toBeInTheDocument()
})

// Guards against the order being left in 'pending' state (see MARK_ORDER_PAID
// dispatch in CheckoutPage) — BookDetailPage's "Read Book" unlock depends on paid orders.
test('order is actually marked paid, unlocking the book on BookDetailPage', () => {
  render(
    <SessionProvider>
      <MemoryRouter initialEntries={['/checkout/b1']}>
        <Routes>
          <Route path="/checkout/:bookId" element={<CheckoutPage />} />
          <Route path="/books/:id" element={<BookDetailPage />} />
        </Routes>
      </MemoryRouter>
    </SessionProvider>
  )
  fireEvent.change(screen.getByLabelText(/email/i), { target: { value: 'buyer@x.com' } })
  fireEvent.click(screen.getByLabelText(/digital/i))
  fireEvent.click(screen.getByRole('button', { name: /pay with razorpay/i }))
  fireEvent.click(screen.getByRole('link', { name: /back to book/i }))
  expect(screen.getByRole('link', { name: /read book/i })).toBeInTheDocument()
})
