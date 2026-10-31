import { render, screen, fireEvent, waitFor, within } from '@testing-library/react'
import { vi } from 'vitest'
import * as adminApi from '../../api/admin'
import AdminCommissionsPage from './AdminCommissionsPage'

afterEach(() => vi.restoreAllMocks())

const commission = (over = {}) => ({
  id: 4, orderId: 7, orderNumber: 'VT-0007', partner: { id: 2, name: 'Ravi', referralCode: 'VTP-ABC123', active: true },
  purchaseType: 'digital', amount: '25.00', status: 'payable', payoutReference: null, payableAt: null, paidAt: null,
  clawbackDue: false, reversedAt: null, createdAt: '2026-10-29 10:00:00', ...over,
})

function setup(rows) {
  vi.spyOn(adminApi, 'fetchCommissionSettings').mockResolvedValue({ digital: '25.00', hardcopy: '30.00' })
  vi.spyOn(adminApi, 'fetchCommissions').mockResolvedValue({
    items: rows, page: 1, perPage: 25, total: rows.length,
    // shape from backend AdminCommissionController::list
    totals: {
      pending: { count: 0, amount: '0.00' }, payable: { count: 1, amount: '25.00' }, paid: { count: 2, amount: '60.00' },
      reversed: { count: 0, amount: '0.00' }, clawbackDue: { count: 0, amount: '0.00' },
    },
  })
  render(<AdminCommissionsPage />)
}

test('renders commission rows and rates from the API', async () => {
  setup([commission({ refundOpen: true })])
  expect(await screen.findByText('VT-0007')).toBeInTheDocument()
  expect(screen.getByText('Ravi')).toBeInTheDocument()
  expect(await screen.findByDisplayValue('30.00')).toBeInTheDocument()
  expect(screen.getByText(/refund open/i)).toBeInTheDocument()
  expect(screen.getByText('₹60.00')).toBeInTheDocument()
  expect(screen.getByText(/paid \(2\)/i)).toBeInTheDocument()
})

test('mark paid requires a payout reference', async () => {
  setup([commission()])
  const paid = vi.spyOn(adminApi, 'markCommissionPaid').mockResolvedValue(commission({ status: 'paid' }))
  const tr = (await screen.findByText('VT-0007')).closest('tr')
  fireEvent.click(within(tr).getByRole('button', { name: /mark paid/i }))
  expect(within(tr).getByText(/payout reference is required/i)).toBeInTheDocument()
  expect(paid).not.toHaveBeenCalled()
  fireEvent.change(within(tr).getByLabelText(/payout reference/i), { target: { value: 'UPI-123' } })
  fireEvent.click(within(tr).getByRole('button', { name: /mark paid/i }))
  await waitFor(() => expect(paid).toHaveBeenCalledWith(4, 'UPI-123'))
})

test('refund_open from the server is shown', async () => {
  setup([commission({ status: 'pending' })])
  vi.spyOn(adminApi, 'markCommissionPayable').mockRejectedValue(Object.assign(new Error('x'), { status: 409, code: 'refund_open' }))
  const tr = (await screen.findByText('VT-0007')).closest('tr')
  fireEvent.click(within(tr).getByRole('button', { name: /mark payable/i }))
  expect(await screen.findByText(/open refund/i)).toBeInTheDocument()
})

test('saving rates sends strings', async () => {
  setup([])
  const save = vi.spyOn(adminApi, 'saveCommissionSettings').mockResolvedValue({ digital: '40.00', hardcopy: '30.00' })
  fireEvent.change(await screen.findByLabelText(/digital commission/i), { target: { value: '40' } })
  fireEvent.click(screen.getByRole('button', { name: /save rates/i }))
  await waitFor(() => expect(save).toHaveBeenCalledWith({ digital: '40', hardcopy: '30.00' }))
})
