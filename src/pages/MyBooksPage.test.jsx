import { render, screen, fireEvent, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { vi } from 'vitest'
import MyBooksPage from './MyBooksPage'
import { fetchLibrary, fetchMyOrders, redeemCode, fetchAccessCode } from '../api/orders'

vi.mock('../api/orders')

const active = { bookId: 1, title: 'Success Mantra', coverUrl: '/c1.jpg', orderId: 11, purchasedAt: '2026-10-20 10:00:00', accessStatus: 'active' }
const revoked = { bookId: 2, title: 'Entrepreneur Vision', coverUrl: '/c2.jpg', orderId: 12, purchasedAt: '2026-10-21 10:00:00', accessStatus: 'revoked' }
const pending = { orderId: 13, bookId: 3, title: 'Habit Builder', coverUrl: '/c3.jpg', purchasedAt: '2026-10-22 10:00:00' }
const hardOrder = {
  id: 20, orderNumber: 'VT-0020', bookTitle: 'Printed Book', purchaseType: 'hardcopy', status: 'paid',
  fulfilmentStatus: 'shipped', trackingCode: 'VTT-ABCD2345',
}
const digitalOrder = { id: 11, orderNumber: 'VT-0011', bookTitle: 'Success Mantra', purchaseType: 'digital', status: 'paid', trackingCode: null }

function renderPage() {
  return render(<MemoryRouter><MyBooksPage /></MemoryRouter>)
}

beforeEach(() => {
  localStorage.setItem('vtee-auth-token', 'tok')
  fetchLibrary.mockResolvedValue({ books: [], pendingRedemptions: [] })
  fetchMyOrders.mockResolvedValue([])
})

afterEach(() => {
  vi.resetAllMocks()
  localStorage.clear()
})

test('without a token shows a login link and calls nothing', () => {
  localStorage.clear()
  renderPage()
  expect(screen.getByRole('link', { name: /log in/i })).toHaveAttribute('href', '/account')
  expect(fetchLibrary).not.toHaveBeenCalled()
})

test('lists purchased books with a Read link only when access is active', async () => {
  fetchLibrary.mockResolvedValue({ books: [active, revoked], pendingRedemptions: [] })
  renderPage()

  const a = (await screen.findByText('Success Mantra')).closest('li')
  expect(within(a).getByRole('link', { name: /read book/i })).toHaveAttribute('href', '/read/1')
  expect(within(a).getByText(/active/i)).toBeInTheDocument()
  expect(within(a).getByText(/2026-10-20/)).toBeInTheDocument()
  expect(within(a).getByRole('img', { name: 'Success Mantra' })).toHaveAttribute('src', '/c1.jpg')

  const r = screen.getByText('Entrepreneur Vision').closest('li')
  expect(within(r).queryByRole('link', { name: /read book/i })).not.toBeInTheDocument()
  expect(within(r).getByText(/revoked/i)).toBeInTheDocument()
  expect(fetchLibrary).toHaveBeenCalledWith('tok')
})

test('redeeming a code adds the book to the list', async () => {
  fetchLibrary
    .mockResolvedValueOnce({ books: [], pendingRedemptions: [] })
    .mockResolvedValue({ books: [active], pendingRedemptions: [] })
  redeemCode.mockResolvedValue({ bookId: 1, orderId: 11 })
  renderPage()

  fireEvent.change(await screen.findByLabelText(/access code/i), { target: { value: 'abcd-efgh-jkmn-pqrs' } })
  fireEvent.click(screen.getByRole('button', { name: /^redeem$/i }))

  expect(await screen.findByText('Success Mantra')).toBeInTheDocument()
  expect(redeemCode).toHaveBeenCalledWith('tok', 'abcd-efgh-jkmn-pqrs')
  expect(screen.getByLabelText(/access code/i)).toHaveValue('')
})

test.each([
  'That code is not valid.',
  'This code has already been redeemed.',
  'This code has been revoked.',
  'The order for this code has not been paid.',
])('shows the server message verbatim: %s', async (message) => {
  redeemCode.mockRejectedValue(new Error(message))
  renderPage()

  fireEvent.change(await screen.findByLabelText(/access code/i), { target: { value: 'ZZZZ' } })
  fireEvent.click(screen.getByRole('button', { name: /^redeem$/i }))

  expect(await screen.findByText(message)).toBeInTheDocument()
})

test('a pending redemption reveals its code only after Show my code, and redeems it', async () => {
  fetchLibrary
    .mockResolvedValueOnce({ books: [], pendingRedemptions: [pending] })
    .mockResolvedValue({ books: [{ ...active, bookId: 3, title: 'Habit Builder' }], pendingRedemptions: [] })
  fetchAccessCode.mockResolvedValue({ code: 'ABCD-EFGH-JKMN-PQRS' })
  redeemCode.mockResolvedValue({ bookId: 3, orderId: 13 })
  renderPage()

  const item = (await screen.findByText('Habit Builder')).closest('li')
  expect(screen.queryByText('ABCD-EFGH-JKMN-PQRS')).not.toBeInTheDocument()
  expect(fetchAccessCode).not.toHaveBeenCalled()

  fireEvent.click(within(item).getByRole('button', { name: /show my code/i }))
  expect(await within(item).findByText('ABCD-EFGH-JKMN-PQRS')).toBeInTheDocument()
  expect(fetchAccessCode).toHaveBeenCalledWith('tok', 13)
  expect(localStorage.getItem('ABCD-EFGH-JKMN-PQRS')).toBeNull()

  fireEvent.click(within(item).getByRole('button', { name: /redeem/i }))
  expect(await screen.findByRole('link', { name: /read book/i })).toHaveAttribute('href', '/read/3')
  expect(redeemCode).toHaveBeenCalledWith('tok', 'ABCD-EFGH-JKMN-PQRS')
  expect(screen.queryByText('ABCD-EFGH-JKMN-PQRS')).not.toBeInTheDocument()
})

test('shows the server error if the access code cannot be fetched', async () => {
  fetchLibrary.mockResolvedValue({ books: [], pendingRedemptions: [pending] })
  fetchAccessCode.mockRejectedValue(new Error('Access code not found'))
  renderPage()

  fireEvent.click(await screen.findByRole('button', { name: /show my code/i }))
  expect(await screen.findByText('Access code not found')).toBeInTheDocument()
})

test('hard-copy orders show the tracking code and status label', async () => {
  fetchMyOrders.mockResolvedValue([digitalOrder, hardOrder])
  renderPage()

  const item = (await screen.findByText(/VT-0020/)).closest('li')
  expect(within(item).getByText(/VTT-ABCD2345/)).toBeInTheDocument()
  expect(within(item).getByText(/Shipped/)).toBeInTheDocument()
  expect(screen.queryByText(/VT-0011/)).not.toBeInTheDocument()
  expect(screen.getByRole('link', { name: /track an order/i })).toHaveAttribute('href', '/track')
})

test('shows an error if the library cannot be loaded', async () => {
  fetchLibrary.mockRejectedValue(new Error('Could not load your books'))
  renderPage()
  expect(await screen.findByText('Could not load your books')).toBeInTheDocument()
})
