import { render, screen, fireEvent } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { vi } from 'vitest'
import TrackOrderPage from './TrackOrderPage'
import { trackOrder } from '../api/orders'

vi.mock('../api/orders')

function renderPage() {
  return render(<MemoryRouter><TrackOrderPage /></MemoryRouter>)
}

function lookUp(code) {
  fireEvent.change(screen.getByLabelText(/tracking code/i), { target: { value: code } })
  fireEvent.click(screen.getByRole('button', { name: /track/i }))
}

afterEach(() => vi.resetAllMocks())

test('shows status label, courier and tracking number, and no address', async () => {
  trackOrder.mockResolvedValue({
    orderNumber: 'VT-0020', bookTitle: 'Printed Book', status: 'shipped', label: 'Shipped',
    updatedAt: '2026-10-25 09:00:00', courier: 'DTDC', trackingNumber: 'D123456',
  })
  renderPage()
  lookUp(' vtt-abcd2345 ')

  expect(await screen.findByText('Shipped')).toBeInTheDocument()
  expect(trackOrder).toHaveBeenCalledWith('VTT-ABCD2345')
  expect(screen.getByText(/VT-0020/)).toBeInTheDocument()
  expect(screen.getByText(/Printed Book/)).toBeInTheDocument()
  expect(screen.getByText(/DTDC/)).toBeInTheDocument()
  expect(screen.getByText(/D123456/)).toBeInTheDocument()
  expect(screen.queryByText(/address|pincode|phone/i)).not.toBeInTheDocument()
})

test('omits courier details when the server has none', async () => {
  trackOrder.mockResolvedValue({ orderNumber: 'VT-1', bookTitle: 'B', status: 'confirmed', label: 'Order Confirmed', updatedAt: '2026-10-25 09:00:00' })
  renderPage()
  lookUp('VTT-ABCD2345')
  expect(await screen.findByText('Order Confirmed')).toBeInTheDocument()
  expect(screen.queryByText(/courier/i)).not.toBeInTheDocument()
})

test('shows a generic not-found message on error', async () => {
  trackOrder.mockRejectedValue(new Error('Tracking code not found'))
  renderPage()
  lookUp('VTT-ZZZZ0000')
  expect(await screen.findByText(/we couldn.t find an order with that tracking code/i)).toBeInTheDocument()
})
