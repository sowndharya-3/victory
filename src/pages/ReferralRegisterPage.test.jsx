import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { vi } from 'vitest'
import { SessionProvider } from '../context/SessionContext'
import * as ordersApi from '../api/orders'
import ReferralRegisterPage from './ReferralRegisterPage'

afterEach(() => vi.restoreAllMocks())

function fill() {
  render(
    <SessionProvider>
      <MemoryRouter><ReferralRegisterPage /></MemoryRouter>
    </SessionProvider>
  )
  fireEvent.change(screen.getByLabelText(/^name$/i), { target: { value: 'Test P' } })
  fireEvent.change(screen.getByLabelText(/educational qualification/i), { target: { value: 'B.Com' } })
  fireEvent.change(screen.getByLabelText(/^place$/i), { target: { value: 'Erode' } })
  fireEvent.change(screen.getByLabelText(/district/i), { target: { value: 'Erode' } })
  fireEvent.change(screen.getByLabelText(/status/i), { target: { value: 'Faculty' } })
  fireEvent.change(screen.getByLabelText(/phone number/i), { target: { value: '9111111111' } })
  fireEvent.change(screen.getByLabelText('WhatsApp Number'), { target: { value: '9111111111' } })
  fireEvent.change(screen.getByLabelText(/gpay.*phonepe/i), { target: { value: 'test@okaxis' } })
  fireEvent.change(screen.getByLabelText(/gmail/i), { target: { value: 'test@gmail.com' } })
  fireEvent.click(screen.getByRole('button', { name: /register/i }))
}

test('posts the application to the real endpoint and shows confirmation', async () => {
  const spy = vi.spyOn(ordersApi, 'submitReferralApplication').mockResolvedValue({ id: 1, applicationStatus: 'pending' })
  fill()
  expect(await screen.findByText(/registration received/i)).toBeInTheDocument()
  expect(spy).toHaveBeenCalledWith({
    name: 'Test P', qualification: 'B.Com', place: 'Erode', district: 'Erode', status: 'Faculty',
    phone: '9111111111', whatsapp: '9111111111', upiId: 'test@okaxis', gmail: 'test@gmail.com',
  })
})

test('shows server field errors next to inputs', async () => {
  vi.spyOn(ordersApi, 'submitReferralApplication').mockRejectedValue(
    Object.assign(new Error('Validation failed'), { status: 400, code: 'validation_error', fields: { whatsapp: 'Enter a 10-digit mobile number' } })
  )
  fill()
  expect(await screen.findByText('Enter a 10-digit mobile number')).toBeInTheDocument()
  expect(screen.queryByText(/registration received/i)).not.toBeInTheDocument()
})

test('shows a duplicate application message on 409', async () => {
  vi.spyOn(ordersApi, 'submitReferralApplication').mockRejectedValue(
    Object.assign(new Error('Duplicate'), { status: 409, code: 'duplicate_application' })
  )
  fill()
  await waitFor(() => expect(screen.getByText(/already have an application/i)).toBeInTheDocument())
})
