import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { vi } from 'vitest'
import * as adminApi from '../../api/admin'
import AdminCodesPanel from './AdminCodesPanel'

afterEach(() => vi.restoreAllMocks())

const order = { id: 9, orderNumber: 'VT-0009', purchaseType: 'digital', status: 'paid', digitalCode: { status: 'active', last4: 'WXYZ' } }

test('reissue shows the new code once and it disappears on dismiss', async () => {
  vi.spyOn(adminApi, 'reissueCode').mockResolvedValue({ code: 'ABCD-EFGH-JKMN-PQRS' })
  const setItem = vi.spyOn(Storage.prototype, 'setItem')
  render(<AdminCodesPanel order={order} onChanged={() => {}} />)
  fireEvent.change(screen.getByLabelText(/reissue reason/i), { target: { value: 'lost email' } })
  fireEvent.click(screen.getByRole('button', { name: /reissue code/i }))
  expect(await screen.findByText('ABCD-EFGH-JKMN-PQRS')).toBeInTheDocument()
  expect(screen.getByText(/give this to the customer/i)).toBeInTheDocument()
  expect(adminApi.reissueCode).toHaveBeenCalledWith(9, 'lost email')
  expect(setItem.mock.calls.some(c => String(c[1]).includes('ABCD-EFGH'))).toBe(false)
  fireEvent.click(screen.getByRole('button', { name: /dismiss/i }))
  expect(screen.queryByText('ABCD-EFGH-JKMN-PQRS')).not.toBeInTheDocument()
})

test('revoke requires a reason and shows the server reason error', async () => {
  const revoke = vi.spyOn(adminApi, 'revokeCode').mockRejectedValue(
    Object.assign(new Error('Validation failed'), { status: 400, code: 'validation_error', fields: { reason: 'Reserved reason' } })
  )
  render(<AdminCodesPanel order={order} onChanged={() => {}} />)
  fireEvent.click(screen.getByRole('button', { name: /revoke code/i }))
  expect(screen.getByText(/enter a reason/i)).toBeInTheDocument()
  expect(revoke).not.toHaveBeenCalled()
  fireEvent.change(screen.getByLabelText(/revoke reason/i), { target: { value: 'customer_request' } })
  fireEvent.click(screen.getByRole('button', { name: /revoke code/i }))
  expect(await screen.findByText('Reserved reason')).toBeInTheDocument()
})

test('verify sends the pasted code, shows the result and clears the input', async () => {
  vi.spyOn(adminApi, 'verifyCode').mockResolvedValue({ status: 'active', bookTitle: 'Tamil Grammar', orderNumber: 'VT-0009', orderStatus: 'paid', redeemed: false })
  render(<AdminCodesPanel order={order} onChanged={() => {}} />)
  const input = screen.getByLabelText(/code to verify/i)
  fireEvent.change(input, { target: { value: 'ABCD-EFGH-JKMN-PQRS' } })
  fireEvent.click(screen.getByRole('button', { name: /verify/i }))
  await waitFor(() => expect(adminApi.verifyCode).toHaveBeenCalledWith('ABCD-EFGH-JKMN-PQRS'))
  expect(await screen.findByText(/not redeemed/i)).toBeInTheDocument()
  expect(input.value).toBe('')
})
