import { useEffect } from 'react'
import { render, screen, fireEvent } from '@testing-library/react'
import { SessionProvider, useSession } from '../../context/SessionContext'
import AdminOrdersPage from './AdminOrdersPage'

function Seed({ children }) {
  const { dispatch } = useSession()

  useEffect(() => {
    dispatch({ type: 'CREATE_ORDER', payload: { bookId: 'b1', customerEmail: 'x@y.com', purchaseType: 'digital', amount: 199, referralPartnerId: null } })
    dispatch({ type: 'CREATE_ORDER', payload: { bookId: 'b1', customerEmail: 'x@y.com', purchaseType: 'hardcopy', amount: 349, referralPartnerId: null } })
  }, [])

  return children
}

test('filters orders by purchase type', () => {
  render(
    <SessionProvider>
      <Seed>
        <AdminOrdersPage />
      </Seed>
    </SessionProvider>
  )
  expect(screen.getAllByRole('row').length).toBe(3) // header + 2 orders
  fireEvent.change(screen.getByLabelText(/filter by type/i), { target: { value: 'digital' } })
  expect(screen.getAllByRole('row').length).toBe(2) // header + 1 digital order
})
