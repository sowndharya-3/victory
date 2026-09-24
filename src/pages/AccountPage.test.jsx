import { render, screen, fireEvent } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { SessionProvider } from '../context/SessionContext'
import AccountPage from './AccountPage'

test('prompts for email when not logged in, then lists purchases', () => {
  render(
    <SessionProvider>
      <MemoryRouter><AccountPage /></MemoryRouter>
    </SessionProvider>
  )
  expect(screen.getByLabelText(/email/i)).toBeInTheDocument()
  fireEvent.change(screen.getByLabelText(/email/i), { target: { value: 'buyer@x.com' } })
  fireEvent.click(screen.getByRole('button', { name: /view my books/i }))
  expect(screen.getByText(/no purchased books yet/i)).toBeInTheDocument()
})
