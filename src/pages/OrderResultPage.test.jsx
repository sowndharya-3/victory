import { render, screen, act } from '@testing-library/react'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { vi } from 'vitest'
import OrderResultPage from './OrderResultPage'
import { fetchMyOrder } from '../api/orders'

vi.mock('../api/orders')

const base = {
  id: 7, orderNumber: 'VT-0007', bookId: 3, bookTitle: 'Success Mantra', purchaseType: 'digital',
  status: 'paid', fulfilmentStatus: 'not_applicable', subtotal: 199, shipping: 0, gst: 0, gstPercent: 0,
  gstMode: 'inclusive', total: 199, currency: 'INR', trackingCode: null, digitalCode: null,
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/orders/7/result']}>
      <Routes><Route path="/orders/:orderId/result" element={<OrderResultPage />} /></Routes>
    </MemoryRouter>
  )
}

beforeEach(() => {
  localStorage.setItem('vtee-auth-token', 'tok')
})

afterEach(() => {
  vi.useRealTimers()
  vi.resetAllMocks()
  localStorage.clear()
})

test('paid immediately: shows confirmation, order number, total and account link', async () => {
  fetchMyOrder.mockResolvedValue(base)
  renderPage()
  expect(await screen.findByText(/payment received/i)).toBeInTheDocument()
  expect(screen.getByText('VT-0007')).toBeInTheDocument()
  expect(screen.getByText('Total payable').parentElement).toHaveTextContent('₹199.00')
  expect(screen.getByRole('link', { name: /your account/i })).toHaveAttribute('href', '/account')
  expect(fetchMyOrder).toHaveBeenCalledWith('tok', '7')
})

test('pending then paid after a poll', async () => {
  vi.useFakeTimers()
  fetchMyOrder.mockResolvedValueOnce({ ...base, status: 'pending' }).mockResolvedValue(base)
  renderPage()
  await act(async () => { await vi.advanceTimersByTimeAsync(0) })
  expect(screen.getByText(/confirming your payment/i)).toBeInTheDocument()
  expect(screen.queryByText(/payment received/i)).not.toBeInTheDocument()

  await act(async () => { await vi.advanceTimersByTimeAsync(3000) })
  expect(screen.getByText(/payment received/i)).toBeInTheDocument()
  expect(fetchMyOrder).toHaveBeenCalledTimes(2)

  await act(async () => { await vi.advanceTimersByTimeAsync(10000) })
  expect(fetchMyOrder).toHaveBeenCalledTimes(2) // polling stopped
})

test('failed payment links back to checkout', async () => {
  fetchMyOrder.mockResolvedValue({ ...base, status: 'failed' })
  renderPage()
  expect(await screen.findByText(/payment failed/i)).toBeInTheDocument()
  expect(screen.getByRole('link', { name: /try again/i })).toHaveAttribute('href', '/checkout/3')
})

test('cancelled is treated as a failed payment', async () => {
  fetchMyOrder.mockResolvedValue({ ...base, status: 'cancelled' })
  renderPage()
  expect(await screen.findByText(/payment failed/i)).toBeInTheDocument()
})

test('still pending after 60s shows the do-not-pay-again message and stops polling', async () => {
  vi.useFakeTimers()
  fetchMyOrder.mockResolvedValue({ ...base, status: 'pending' })
  renderPage()
  await act(async () => { await vi.advanceTimersByTimeAsync(60000) })
  expect(screen.getByText(/still confirming your payment/i)).toBeInTheDocument()
  expect(screen.getByText(/you don.t need to pay again/i)).toBeInTheDocument()
  const calls = fetchMyOrder.mock.calls.length
  await act(async () => { await vi.advanceTimersByTimeAsync(30000) })
  expect(fetchMyOrder).toHaveBeenCalledTimes(calls)
})

test('hard copy paid shows shipping city and the email promise', async () => {
  fetchMyOrder.mockResolvedValue({
    ...base, purchaseType: 'hardcopy', fulfilmentStatus: 'confirmed', shipping: 40, total: 239,
    address: { fullName: 'A', city: 'Chennai', pincode: '600001' },
  })
  renderPage()
  expect(await screen.findByText(/payment received/i)).toBeInTheDocument()
  expect(screen.getByText(/ship to chennai/i)).toBeInTheDocument()
  expect(screen.getByText(/we will email you when it ships/i)).toBeInTheDocument()
})

test('fetch error shows the error message', async () => {
  fetchMyOrder.mockRejectedValue(new Error('Order not found'))
  renderPage()
  expect(await screen.findByText('Order not found')).toBeInTheDocument()
})

test('without a token asks the user to log in', () => {
  localStorage.clear()
  renderPage()
  expect(screen.getByRole('link', { name: /log in/i })).toHaveAttribute('href', '/account')
  expect(fetchMyOrder).not.toHaveBeenCalled()
})
