import { useEffect } from 'react'
import { render, screen, fireEvent } from '@testing-library/react'
import { SessionProvider, useSession } from '../../context/SessionContext'
import AdminCommissionsPage from './AdminCommissionsPage'

function Seed({ children }) {
  const { dispatch } = useSession()
  useEffect(() => {
    dispatch({ type: 'CREATE_ORDER', payload: { bookId: 'b1', customerEmail: 'x@y.com', purchaseType: 'digital', amount: 199, referralPartnerId: 'r1' } })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])
  return children
}

test('only counts commission for paid orders with a referral partner', () => {
  render(
    <SessionProvider>
      <Seed><AdminCommissionsPage /></Seed>
    </SessionProvider>
  )
  // Order created above is still 'pending' — no commission should show yet.
  expect(screen.getByText(/total commission: ₹0/i)).toBeInTheDocument()
})

test('commission rate is editable', () => {
  render(<SessionProvider><AdminCommissionsPage /></SessionProvider>)
  fireEvent.change(screen.getByLabelText(/digital commission/i), { target: { value: '30' } })
  fireEvent.click(screen.getByRole('button', { name: /save rates/i }))
  expect(screen.getByLabelText(/digital commission/i)).toHaveValue(30)
})
