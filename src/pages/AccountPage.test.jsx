import { render, screen, fireEvent, within, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { vi } from 'vitest'
import { SessionProvider } from '../context/SessionContext'
import AccountPage from './AccountPage'
import * as authApi from '../api/auth'
import * as ordersApi from '../api/orders'

afterEach(() => {
  vi.restoreAllMocks()
  localStorage.clear()
})

async function logIn(orders) {
  vi.spyOn(authApi, 'loginCustomer').mockResolvedValue({
    token: 'fake-token',
    user: { id: 1, name: 'Jane', email: 'jane@example.com' },
  })
  const fetchMyOrders = vi.spyOn(ordersApi, 'fetchMyOrders').mockResolvedValue(orders)

  render(
    <SessionProvider>
      <MemoryRouter><AccountPage /></MemoryRouter>
    </SessionProvider>
  )

  fireEvent.change(screen.getByLabelText(/email/i), { target: { value: 'jane@example.com' } })
  fireEvent.change(screen.getByLabelText(/password/i), { target: { value: 'password123' } })
  fireEvent.click(screen.getByRole('button', { name: /log in/i }))
  return fetchMyOrders
}

const order = {
  id: 1, orderNumber: 'VT-0001', bookId: 1, bookTitle: 'Success Mantra', purchaseType: 'digital', status: 'paid',
  fulfilmentStatus: 'not_applicable', total: 199, createdAt: '2026-10-20 10:00:00', trackingCode: null, digitalCode: null,
}

test('logging in with real backend credentials shows the empty order list', async () => {
  const fetchMyOrders = await logIn([])

  expect(await screen.findByText(/no orders yet/i)).toBeInTheDocument()
  expect(fetchMyOrders).toHaveBeenCalledWith('fake-token')
})

test('lists real orders with type, status, fulfilment status for hard copy and total', async () => {
  await logIn([
    order,
    { ...order, id: 2, orderNumber: 'VT-0002', bookTitle: 'Entrepreneur Vision', purchaseType: 'hardcopy', fulfilmentStatus: 'shipped', total: 389.5 },
    { ...order, id: 3, orderNumber: 'VT-0003', status: 'pending' },
  ])

  const digital = (await screen.findByText(/VT-0001/)).closest('li')
  expect(within(digital).getByText(/Success Mantra/)).toBeInTheDocument()
  expect(within(digital).getByText(/digital/i)).toBeInTheDocument()
  expect(within(digital).getByText(/paid/i)).toBeInTheDocument()
  expect(within(digital).getByText(/₹199.00/)).toBeInTheDocument()
  expect(within(digital).queryByText(/shipped/i)).not.toBeInTheDocument()

  const hard = screen.getByText(/VT-0002/).closest('li')
  expect(within(hard).getByText(/hard copy/i)).toBeInTheDocument()
  expect(within(hard).getByText(/shipped/i)).toBeInTheDocument()
  expect(within(hard).getByText(/₹389.50/)).toBeInTheDocument()

  expect(within(digital.closest('ul')).getAllByRole('listitem')).toHaveLength(3)
  expect(screen.queryByText(/no orders yet/i)).not.toBeInTheDocument()
})

test('shows an error if orders cannot be loaded', async () => {
  vi.spyOn(authApi, 'loginCustomer').mockResolvedValue({ token: 't', user: { email: 'j@example.com' } })
  vi.spyOn(ordersApi, 'fetchMyOrders').mockRejectedValue(new Error('Could not load orders'))
  render(<SessionProvider><MemoryRouter><AccountPage /></MemoryRouter></SessionProvider>)
  fireEvent.change(screen.getByLabelText(/email/i), { target: { value: 'j@example.com' } })
  fireEvent.change(screen.getByLabelText(/password/i), { target: { value: 'password123' } })
  fireEvent.click(screen.getByRole('button', { name: /log in/i }))
  expect(await screen.findByText('Could not load orders')).toBeInTheDocument()
})

test('shows the backend error message on failed login', async () => {
  vi.spyOn(authApi, 'loginCustomer').mockRejectedValue(new Error('Invalid email or password'))

  render(
    <SessionProvider>
      <MemoryRouter><AccountPage /></MemoryRouter>
    </SessionProvider>
  )

  fireEvent.change(screen.getByLabelText(/email/i), { target: { value: 'jane@example.com' } })
  fireEvent.change(screen.getByLabelText(/password/i), { target: { value: 'wrong' } })
  fireEvent.click(screen.getByRole('button', { name: /log in/i }))

  await waitFor(() => {
    expect(screen.getByText(/invalid email or password/i)).toBeInTheDocument()
  })
})
