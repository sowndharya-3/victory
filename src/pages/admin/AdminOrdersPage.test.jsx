import { render, screen, fireEvent, waitFor, within } from '@testing-library/react'
import { vi } from 'vitest'
import * as adminApi from '../../api/admin'
import AdminOrdersPage from './AdminOrdersPage'

afterEach(() => vi.restoreAllMocks())

const row = (over = {}) => ({
  id: 7, orderNumber: 'VT-0007', customer: { id: 3, name: 'Asha', email: 'asha@example.com' },
  book: { id: 1, title: 'Tamil Grammar' }, purchaseType: 'hardcopy', status: 'paid', amount: 349,
  paidAt: '2026-10-29 10:00:00', createdAt: '2026-10-29 09:59:00', razorpayPaymentId: 'pay_1',
  digitalCode: null, trackingCode: 'VTT-ABCD1234', fulfilmentStatus: 'confirmed', adminFlag: null, ...over,
})

const detail = (over = {}) => ({
  ...row(over), customer: { id: 3, name: 'Asha', email: 'asha@example.com', phone: '9876543210' },
  breakdown: { subtotal: 349, shipping: 0, gst: 0, gstPercent: 0, gstMode: 'inclusive', total: 349, currency: 'INR' },
  address: { name: 'Asha', line1: '1 Main St', city: 'Erode', state: 'TN', pincode: '638001', phone: '9876543210' },
  courier: null, trackingNumber: null, shippedAt: null, referralCode: null, refunds: [], commission: null, auditLog: [],
  ...over,
})

function list(items) {
  return { items, page: 1, perPage: 25, total: items.length }
}

test('renders order rows from the API', async () => {
  vi.spyOn(adminApi, 'fetchAdminOrders').mockResolvedValue(list([row()]))
  render(<AdminOrdersPage />)
  expect(await screen.findByText('VT-0007')).toBeInTheDocument()
  expect(screen.getByText('Tamil Grammar')).toBeInTheDocument()
  expect(screen.getByText('₹349.00')).toBeInTheDocument()
})

test('search sends q to the API', async () => {
  const spy = vi.spyOn(adminApi, 'fetchAdminOrders').mockResolvedValue(list([]))
  render(<AdminOrdersPage />)
  await screen.findByText(/no orders/i)
  fireEvent.change(screen.getByLabelText(/search/i), { target: { value: 'asha' } })
  fireEvent.click(screen.getByRole('button', { name: /^search$/i }))
  await waitFor(() => expect(spy).toHaveBeenLastCalledWith(expect.objectContaining({ q: 'asha' })))
})

test('confirmed hard copy offers only processing, no cancel, and a refund action', async () => {
  vi.spyOn(adminApi, 'fetchAdminOrders').mockResolvedValue(list([row()]))
  vi.spyOn(adminApi, 'fetchAdminOrder').mockResolvedValue(detail())
  render(<AdminOrdersPage />)
  fireEvent.click(await screen.findByRole('button', { name: /view/i }))
  const select = await screen.findByLabelText(/next status/i)
  const options = within(select).getAllByRole('option').map(o => o.value).filter(Boolean)
  expect(options).toEqual(['processing'])
  expect(screen.getByRole('button', { name: /refund this order/i })).toBeInTheDocument()
})

test('shipped hard copy offers out for delivery, delivered and cancel with a required reason', async () => {
  vi.spyOn(adminApi, 'fetchAdminOrders').mockResolvedValue(list([row({ fulfilmentStatus: 'shipped' })]))
  vi.spyOn(adminApi, 'fetchAdminOrder').mockResolvedValue(detail({ fulfilmentStatus: 'shipped', courier: 'DTDC' }))
  const update = vi.spyOn(adminApi, 'updateFulfilment').mockResolvedValue(detail({ fulfilmentStatus: 'cancelled' }))
  render(<AdminOrdersPage />)
  fireEvent.click(await screen.findByRole('button', { name: /view/i }))
  const select = await screen.findByLabelText(/next status/i)
  const options = within(select).getAllByRole('option').map(o => o.value).filter(Boolean)
  expect(options).toEqual(['out_for_delivery', 'delivered', 'cancelled'])
  fireEvent.change(select, { target: { value: 'cancelled' } })
  fireEvent.click(screen.getByRole('button', { name: /update status/i }))
  expect(await screen.findByText(/enter a reason/i)).toBeInTheDocument()
  expect(update).not.toHaveBeenCalled()
})

test('refund_required from the server shows a clear message', async () => {
  vi.spyOn(adminApi, 'fetchAdminOrders').mockResolvedValue(list([row({ fulfilmentStatus: 'processing' })]))
  vi.spyOn(adminApi, 'fetchAdminOrder').mockResolvedValue(detail({ fulfilmentStatus: 'processing' }))
  vi.spyOn(adminApi, 'updateFulfilment').mockRejectedValue(Object.assign(new Error('x'), { status: 409, code: 'refund_required' }))
  render(<AdminOrdersPage />)
  fireEvent.click(await screen.findByRole('button', { name: /view/i }))
  fireEvent.change(await screen.findByLabelText(/next status/i), { target: { value: 'shipped' } })
  fireEvent.change(screen.getByLabelText(/courier/i), { target: { value: 'DTDC' } })
  fireEvent.click(screen.getByRole('button', { name: /update status/i }))
  expect(await screen.findByText(/refund it instead/i)).toBeInTheDocument()
})

test('a pasted full access code is not sent as a search query', async () => {
  const spy = vi.spyOn(adminApi, 'fetchAdminOrders').mockResolvedValue(list([]))
  render(<AdminOrdersPage />)
  await screen.findByText(/no orders/i)
  fireEvent.change(screen.getByLabelText(/search/i), { target: { value: 'ABCD-EFGH-JKMN-PQRS' } })
  fireEvent.click(screen.getByRole('button', { name: /^search$/i }))
  expect(screen.getByText(/use the code panel/i)).toBeInTheDocument()
  expect(spy).toHaveBeenCalledTimes(1)
})

test('a stale slower response does not overwrite a newer one', async () => {
  let resolveFirst
  vi.spyOn(adminApi, 'fetchAdminOrders')
    .mockReturnValueOnce(new Promise(r => { resolveFirst = r }))
    .mockResolvedValueOnce(list([row({ id: 2, orderNumber: 'VT-NEW' })]))
  render(<AdminOrdersPage />)
  fireEvent.change(screen.getByLabelText(/^type$/i), { target: { value: 'hardcopy' } })
  expect(await screen.findByText('VT-NEW')).toBeInTheDocument()
  resolveFirst(list([row({ id: 1, orderNumber: 'VT-OLD' })]))
  await new Promise(r => setTimeout(r, 20))
  expect(screen.queryByText('VT-OLD')).not.toBeInTheDocument()
  expect(screen.getByText('VT-NEW')).toBeInTheDocument()
})
