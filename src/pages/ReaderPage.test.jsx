import { useEffect } from 'react'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { SessionProvider, useSession } from '../context/SessionContext'
import ReaderPage from './ReaderPage'

// Test-only seeding wrapper: SessionProvider has no preloadedState prop, so we
// drive real reducer actions via useSession() until a paid order matching
// {bookId, purchaseType, customerEmail} exists, then render children.
function SeedPaidOrder({ email, bookId, purchaseType, children }) {
  const { state, dispatch } = useSession()
  const order = state.orders.find(
    o => o.bookId === bookId && o.purchaseType === purchaseType && o.customerEmail === email
  )

  useEffect(() => {
    if (state.customerEmail !== email) {
      dispatch({ type: 'LOGIN_CUSTOMER', payload: { email } })
    } else if (!order) {
      dispatch({ type: 'CREATE_ORDER', payload: { bookId, purchaseType, customerEmail: email } })
    } else if (order.paymentStatus !== 'paid') {
      dispatch({ type: 'MARK_ORDER_PAID', payload: { orderId: order.id } })
    }
  })

  const ready = state.customerEmail === email && order?.paymentStatus === 'paid'
  return ready ? children : null
}

test('blocks access when book was not purchased', () => {
  render(
    <SessionProvider>
      <MemoryRouter initialEntries={['/read/b1']}>
        <Routes><Route path="/read/:bookId" element={<ReaderPage />} /></Routes>
      </MemoryRouter>
    </SessionProvider>
  )
  expect(screen.getByText(/you don't have access/i)).toBeInTheDocument()
})

test('blocks access when a paid order exists but purchaseType does not match', () => {
  render(
    <SessionProvider>
      <SeedPaidOrder email="buyer@example.com" bookId="b1" purchaseType="hardcopy">
        <MemoryRouter initialEntries={['/read/b1']}>
          <Routes><Route path="/read/:bookId" element={<ReaderPage />} /></Routes>
        </MemoryRouter>
      </SeedPaidOrder>
    </SessionProvider>
  )
  expect(screen.getByText(/you don't have access/i)).toBeInTheDocument()
})

test('grants access when a paid digital order matches book and customer', () => {
  render(
    <SessionProvider>
      <SeedPaidOrder email="buyer@example.com" bookId="b1" purchaseType="digital">
        <MemoryRouter initialEntries={['/read/b1']}>
          <Routes><Route path="/read/:bookId" element={<ReaderPage />} /></Routes>
        </MemoryRouter>
      </SeedPaidOrder>
    </SessionProvider>
  )
  expect(screen.queryByText(/you don't have access/i)).not.toBeInTheDocument()
  expect(screen.getByText(/Licensed to buyer@example.com/)).toBeInTheDocument()
  expect(screen.getByAltText('Page 1')).toBeInTheDocument()
})
