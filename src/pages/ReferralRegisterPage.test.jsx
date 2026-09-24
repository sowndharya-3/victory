import { render, screen, fireEvent } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { SessionProvider } from '../context/SessionContext'
import ReferralRegisterPage from './ReferralRegisterPage'

test('submits all 9 fields and shows confirmation', () => {
  render(
    <SessionProvider>
      <MemoryRouter><ReferralRegisterPage /></MemoryRouter>
    </SessionProvider>
  )
  fireEvent.change(screen.getByLabelText(/^name$/i), { target: { value: 'Test P' } })
  fireEvent.change(screen.getByLabelText(/educational qualification/i), { target: { value: 'B.Com' } })
  fireEvent.change(screen.getByLabelText(/^place$/i), { target: { value: 'Erode' } })
  fireEvent.change(screen.getByLabelText(/district/i), { target: { value: 'Erode' } })
  fireEvent.change(screen.getByLabelText(/status/i), { target: { value: 'Student' } })
  fireEvent.change(screen.getByLabelText(/phone number/i), { target: { value: '9111111111' } })
  fireEvent.change(screen.getByLabelText(/whatsapp/i), { target: { value: '9111111111' } })
  fireEvent.change(screen.getByLabelText(/gpay.*phonepe/i), { target: { value: 'test@okaxis' } })
  fireEvent.change(screen.getByLabelText(/gmail/i), { target: { value: 'test@gmail.com' } })
  fireEvent.click(screen.getByRole('button', { name: /register/i }))
  expect(screen.getByText(/registration received/i)).toBeInTheDocument()
})
