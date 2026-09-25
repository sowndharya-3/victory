import { useEffect, useState } from 'react'
import { render, screen, fireEvent } from '@testing-library/react'
import { SessionProvider, useSession } from '../../context/SessionContext'
import AdminCommissionsPage from './AdminCommissionsPage'

// Creates an order then marks it paid once it lands in state — avoids hardcoding
// an order id, since nextOrderId is a shared module-level counter across tests.
function SeedPaidOrder({ payload, children }) {
  const { state, dispatch } = useSession()
  const [created, setCreated] = useState(false)
  useEffect(() => {
    if (!created) {
      dispatch({ type: 'CREATE_ORDER', payload })
      setCreated(true)
    }
  }, [created]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!created) return
    const order = state.orders.find(o => o.customerEmail === payload.customerEmail && o.paymentStatus === 'pending')
    if (order) dispatch({ type: 'MARK_ORDER_PAID', payload: { orderId: order.id } })
  }, [created, state.orders]) // eslint-disable-line react-hooks/exhaustive-deps

  return children
}

function CreatePendingOrder({ payload, children }) {
  const { dispatch } = useSession()
  useEffect(() => {
    dispatch({ type: 'CREATE_ORDER', payload })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])
  return children
}

test('only counts commission for paid orders with a referral partner', () => {
  render(
    <SessionProvider>
      <CreatePendingOrder payload={{ bookId: 'b1', customerEmail: 'x@y.com', purchaseType: 'digital', amount: 199, referralPartnerId: 'r1' }}>
        <AdminCommissionsPage />
      </CreatePendingOrder>
    </SessionProvider>
  )
  // Order created above is still 'pending' — no commission should show yet.
  expect(screen.getByText(/total commission: ₹0/i)).toBeInTheDocument()
})

test('shows the snapshotted commission for a paid order and sums it into the total', () => {
  render(
    <SessionProvider>
      <SeedPaidOrder payload={{ bookId: 'b1', customerEmail: 'x@y.com', purchaseType: 'digital', amount: 199, referralPartnerId: 'r1' }}>
        <AdminCommissionsPage />
      </SeedPaidOrder>
    </SessionProvider>
  )
  // Default digital commission rate is ₹25.
  expect(screen.getByText('₹25')).toBeInTheDocument()
  expect(screen.getByText(/total commission: ₹25/i)).toBeInTheDocument()
})

test('changing the rate only affects orders created afterwards, not past ones', () => {
  render(
    <SessionProvider>
      <SeedPaidOrder payload={{ bookId: 'b1', customerEmail: 'old@y.com', purchaseType: 'digital', amount: 199, referralPartnerId: 'r1' }}>
        <SeedPaidOrder payload={{ bookId: 'b1', customerEmail: 'new@y.com', purchaseType: 'digital', amount: 199, referralPartnerId: 'r1' }}>
          <AdminCommissionsPage />
        </SeedPaidOrder>
      </SeedPaidOrder>
    </SessionProvider>
  )

  // Both orders paid at the original ₹25 rate.
  expect(screen.getAllByText('₹25')).toHaveLength(2)

  fireEvent.change(screen.getByLabelText(/digital commission/i), { target: { value: '30' } })
  fireEvent.click(screen.getByRole('button', { name: /save rates/i }))

  // state.commissionRate actually changed (proven below by a NEW order snapshotting
  // the new rate) — and the two pre-existing orders' commissions are unchanged.
  expect(screen.getAllByText('₹25')).toHaveLength(2)
  expect(screen.getByText(/total commission: ₹50/i)).toBeInTheDocument()
})

test('a new order created after a rate change snapshots the new rate', () => {
  // Gate order creation on the rate having actually changed in state, so it can't
  // race ahead of the rate-change dispatch (child effects run before parent effects).
  function ChangeRateThenSeedOrder({ payload, children }) {
    const { state, dispatch } = useSession()
    useEffect(() => {
      dispatch({ type: 'SET_COMMISSION_RATE', payload: { digital: 30 } })
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [])
    if (state.commissionRate.digital !== 30) return null
    return <SeedPaidOrder payload={payload}>{children}</SeedPaidOrder>
  }
  render(
    <SessionProvider>
      <ChangeRateThenSeedOrder payload={{ bookId: 'b1', customerEmail: 'x@y.com', purchaseType: 'digital', amount: 199, referralPartnerId: 'r1' }}>
        <AdminCommissionsPage />
      </ChangeRateThenSeedOrder>
    </SessionProvider>
  )
  expect(screen.getByText('₹30')).toBeInTheDocument()
  expect(screen.getByText(/total commission: ₹30/i)).toBeInTheDocument()
})
