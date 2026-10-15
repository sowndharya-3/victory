import { useEffect } from 'react'
import { render, screen, fireEvent, within, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { vi } from 'vitest'
import { SessionProvider, useSession } from '../context/SessionContext'
import AccountPage from './AccountPage'
import * as authApi from '../api/auth'

test('logging in with real backend credentials sets customerEmail and shows the order list', async () => {
  vi.spyOn(authApi, 'loginCustomer').mockResolvedValue({
    token: 'fake-token',
    user: { id: 1, name: 'Jane', email: 'jane@example.com' },
  })

  render(
    <SessionProvider>
      <MemoryRouter><AccountPage /></MemoryRouter>
    </SessionProvider>
  )

  fireEvent.change(screen.getByLabelText(/email/i), { target: { value: 'jane@example.com' } })
  fireEvent.change(screen.getByLabelText(/password/i), { target: { value: 'password123' } })
  fireEvent.click(screen.getByRole('button', { name: /log in/i }))

  await waitFor(() => {
    expect(screen.getByText(/no purchased books yet/i)).toBeInTheDocument()
  })
})

test('shows the backend error message on failed login', async () => {
  vi.spyOn(authApi, 'loginCustomer').mockRejectedValue(new Error('Invalid email or password'))

  render(
    <SessionProvider>
      <MemoryRouter><AccountPage /></MemoryRouter>
    </SessionProvider>
  )

  fireEvent.change(screen.getByLabelText(/email/i), { target: { value: 'jane@example.com' } })
  fireEvent.change(screen.getByLabelText(/password/i), { target: { value: 'wrong' } })
  fireEvent.click(screen.getByRole('button', { name: /log in/i }))

  await waitFor(() => {
    expect(screen.getByText(/invalid email or password/i)).toBeInTheDocument()
  })
})

// Test-only seeding wrapper (same pattern as ReaderPage.test.jsx): drives real
// LOGIN_CUSTOMER/CREATE_ORDER/MARK_ORDER_PAID actions via useSession() until every
// requested order exists in the requested payment state, then renders children.
// `paid: false` leaves an order at its created (pending) status.
function SeedOrdersAndLogin({ email, orders, children }) {
  const { state, dispatch } = useSession()

  const findOrder = spec => state.orders.find(
    o => o.bookId === spec.bookId && o.purchaseType === spec.purchaseType && o.customerEmail === spec.customerEmail
  )

  useEffect(() => {
    if (state.customerEmail !== email) {
      dispatch({ type: 'LOGIN_CUSTOMER', payload: { email } })
      return
    }
    for (const spec of orders) {
      const order = findOrder(spec)
      if (!order) {
        dispatch({ type: 'CREATE_ORDER', payload: { bookId: spec.bookId, purchaseType: spec.purchaseType, customerEmail: spec.customerEmail } })
        return
      }
      if (spec.paid && order.paymentStatus !== 'paid') {
        dispatch({ type: 'MARK_ORDER_PAID', payload: { orderId: order.id } })
        return
      }
    }
  })

  const ready = state.customerEmail === email && orders.every(spec => {
    const order = findOrder(spec)
    return order && (!spec.paid || order.paymentStatus === 'paid')
  })
  return ready ? children : null
}

test('lists only this customer\'s paid orders, gating the Read link on purchaseType', () => {
  render(
    <SessionProvider>
      <SeedOrdersAndLogin
        email="buyer@example.com"
        orders={[
          { bookId: 'b1', purchaseType: 'digital', customerEmail: 'buyer@example.com', paid: true },
          { bookId: 'b2', purchaseType: 'hardcopy', customerEmail: 'buyer@example.com', paid: true },
          { bookId: 'b2', purchaseType: 'digital', customerEmail: 'buyer@example.com', paid: false },
          { bookId: 'b1', purchaseType: 'digital', customerEmail: 'other@example.com', paid: true },
        ]}
      >
        <MemoryRouter><AccountPage /></MemoryRouter>
      </SeedOrdersAndLogin>
    </SessionProvider>
  )

  // b1 digital paid order for this customer: appears, with a Read link.
  const b1Item = screen.getByText(/Success Mantra/).closest('li')
  expect(b1Item).not.toBeNull()
  expect(within(b1Item).getByRole('link', { name: /read/i })).toBeInTheDocument()

  // b2 hardcopy paid order for this customer: appears, without a Read link.
  const b2Item = screen.getByText(/Entrepreneur Vision/).closest('li')
  expect(b2Item).not.toBeNull()
  expect(within(b2Item).queryByRole('link', { name: /read/i })).not.toBeInTheDocument()

  // Only two orders qualify (the pending one and the other customer's order are excluded).
  expect(within(b1Item.closest('ul')).getAllByRole('listitem')).toHaveLength(2)
})
