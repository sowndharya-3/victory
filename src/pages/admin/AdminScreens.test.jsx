import { render, screen, fireEvent, waitFor, within } from '@testing-library/react'
import { vi } from 'vitest'
import * as adminApi from '../../api/admin'
import AdminDashboardPage from './AdminDashboardPage'
import AdminCustomersPage from './AdminCustomersPage'
import AdminRefundsPage from './AdminRefundsPage'
import AdminSettingsPage from './AdminSettingsPage'

afterEach(() => vi.restoreAllMocks())

test('dashboard renders the API summary', async () => {
  vi.spyOn(adminApi, 'fetchDashboard').mockResolvedValue({
    publishedBooks: 2, totalCustomers: 11, digitalPurchases: 5, hardcopyOrders: 3, successfulPayments: 9,
    pendingOrders: 1, revenue: 1234.5, refundedAmount: 0, pendingRefunds: 2, commissionPending: 50, commissionPayable: 25,
  })
  render(<AdminDashboardPage />)
  expect(await screen.findByText('₹1234.50')).toBeInTheDocument()
  expect(screen.getByText('11')).toBeInTheDocument()
})

test('customers page renders API rows', async () => {
  vi.spyOn(adminApi, 'fetchAdminCustomers').mockResolvedValue({
    items: [{ id: 3, name: 'Asha', email: 'asha@example.com', phone: '9876543210', createdAt: '2026-10-01', orderCount: 2, paidTotal: 548 }],
    page: 1, perPage: 25, total: 1,
  })
  render(<AdminCustomersPage />)
  expect(await screen.findByText('asha@example.com')).toBeInTheDocument()
  expect(screen.getByText('₹548.00')).toBeInTheDocument()
})

test('refunds queue renders rows; reject needs a note', async () => {
  vi.spyOn(adminApi, 'fetchRefunds').mockResolvedValue({
    items: [{ id: 8, orderId: 7, orderNumber: 'VT-0007', purchaseType: 'digital', status: 'requested', reason: 'wrong book', adminNote: null, amount: 199, createdAt: '2026-10-29' }],
    page: 1, perPage: 1, total: 1,
  })
  const reject = vi.spyOn(adminApi, 'rejectRefund').mockResolvedValue({})
  const approve = vi.spyOn(adminApi, 'approveRefund').mockResolvedValue({})
  render(<AdminRefundsPage />)
  const tr = (await screen.findByText('VT-0007')).closest('tr')
  expect(within(tr).getByText('wrong book')).toBeInTheDocument()
  fireEvent.click(within(tr).getByRole('button', { name: /reject/i }))
  expect(within(tr).getByText(/note is required/i)).toBeInTheDocument()
  expect(reject).not.toHaveBeenCalled()
  fireEvent.click(within(tr).getByRole('button', { name: /approve/i }))
  await waitFor(() => expect(approve).toHaveBeenCalledWith(8, ''))
})

test('settings saves strings and shows server validation errors', async () => {
  vi.spyOn(adminApi, 'fetchPricingSettings').mockResolvedValue({
    shippingChargeDigital: '0.00', shippingChargeHardcopy: '0.00', gstPercentDigital: '0.00', gstPercentHardcopy: '0.00', gstMode: 'inclusive',
  })
  const save = vi.spyOn(adminApi, 'savePricingSettings').mockRejectedValue(
    Object.assign(new Error('Validation failed'), { status: 400, code: 'validation_error', fields: { gstPercentHardcopy: 'Must be 0-100' } })
  )
  render(<AdminSettingsPage />)
  expect(screen.getByText(/not yet confirmed/i)).toBeInTheDocument()
  fireEvent.change(await screen.findByLabelText(/gst % \(hard copy\)/i), { target: { value: '150' } })
  fireEvent.click(screen.getByRole('button', { name: /save settings/i }))
  await waitFor(() => expect(save).toHaveBeenCalledWith(expect.objectContaining({ gstPercentHardcopy: '150', gstMode: 'inclusive' })))
  expect(await screen.findByText('Must be 0-100')).toBeInTheDocument()
})
