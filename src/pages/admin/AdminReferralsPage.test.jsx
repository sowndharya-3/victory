import { render, screen } from '@testing-library/react'
import { within } from '@testing-library/react'
import { SessionProvider } from '../../context/SessionContext'
import AdminReferralsPage from './AdminReferralsPage'
import { fireEvent } from '@testing-library/react'

test('approving a pending partner marks it approved and active', () => {
  render(<SessionProvider><AdminReferralsPage /></SessionProvider>)
  const row = screen.getByText('Arun S').closest('tr')
  expect(row).toHaveTextContent(/pending/i)
  fireEvent.click(within(row).getByRole('button', { name: /approve/i }))
  expect(row).toHaveTextContent(/approved/i)
})
