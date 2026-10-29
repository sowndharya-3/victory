import { render, screen, fireEvent, waitFor, act } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { SessionProvider } from '../context/SessionContext'
import CheckoutPage from './CheckoutPage'
import { fetchBook } from '../api/books'
import { fetchQuote, validateReferralCode, createOrder, verifyPayment } from '../api/orders'
import { loadRazorpay } from '../utils/razorpay'
import { getStoredReferral } from '../utils/referral'

vi.mock('../api/books', () => ({ fetchBook: vi.fn() }))
vi.mock('../api/orders', () => ({
  fetchQuote: vi.fn(),
  validateReferralCode: vi.fn(),
  createOrder: vi.fn(),
  verifyPayment: vi.fn(),
}))
vi.mock('../utils/razorpay', () => ({ loadRazorpay: vi.fn() }))
vi.mock('../utils/referral', () => ({ getStoredReferral: vi.fn(() => '') }))

const book = { id: 1, title: 'Success Mantra', onlinePrice: 199, hardcopyPrice: 349 }
const digitalQuote = { subtotal: 199, shipping: 0, gst: 0, gstPercent: '5', gstMode: 'inclusive', total: 199 }
const hardQuote = { subtotal: 349, shipping: 50, gst: 0, gstPercent: '5', gstMode: 'inclusive', total: 399 }
const created = {
  order: { id: 77 },
  razorpay: { keyId: 'rzp_test_1', orderId: 'order_X', amount: 19900, currency: 'INR' },
}

let rzpOptions
let rzpHandlers
let open

function RzpStub(options) {
  rzpOptions = options
  this.on = (event, cb) => { rzpHandlers[event] = cb }
  this.open = open
}

function renderAt(path = '/checkout/1') {
  return render(
    <SessionProvider>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/checkout/:bookId" element={<CheckoutPage />} />
          <Route path="/orders/:id/result" element={<p>Result page</p>} />
          <Route path="/account" element={<p>Account page</p>} />
        </Routes>
      </MemoryRouter>
    </SessionProvider>
  )
}

// Pay stays disabled until the server quote has loaded.
const payButton = async () => {
  const btn = await screen.findByRole('button', { name: /pay with razorpay/i })
  await waitFor(() => expect(btn).toBeEnabled())
  return btn
}

beforeEach(() => {
  localStorage.setItem('vtee-auth-token', 'tok')
  rzpOptions = undefined
  rzpHandlers = {}
  open = vi.fn()
  fetchBook.mockResolvedValue(book)
  fetchQuote.mockImplementation((_id, type) => Promise.resolve(type === 'hardcopy' ? hardQuote : digitalQuote))
  validateReferralCode.mockResolvedValue({ valid: false })
  createOrder.mockResolvedValue(created)
  verifyPayment.mockResolvedValue({ order: { id: 77 } })
  loadRazorpay.mockResolvedValue(RzpStub)
  getStoredReferral.mockReturnValue('')
})

afterEach(() => {
  vi.clearAllMocks()
  localStorage.clear()
})

test('shows "Book not found." when the book cannot be loaded', async () => {
  fetchBook.mockRejectedValue(new Error('Book not found'))
  renderAt('/checkout/999')
  expect(await screen.findByText(/book not found/i)).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /pay with razorpay/i })).not.toBeInTheDocument()
})

test('asks anonymous visitors to log in and offers no Pay button', async () => {
  localStorage.removeItem('vtee-auth-token')
  renderAt()
  const link = await screen.findByRole('link', { name: /log in to continue/i })
  expect(link).toHaveAttribute('href', '/account')
  expect(screen.queryByRole('button', { name: /pay with razorpay/i })).not.toBeInTheDocument()
})

test('renders the server price breakdown, including zero shipping and GST', async () => {
  renderAt()
  expect(await screen.findByText('Total payable')).toBeInTheDocument()
  expect(fetchQuote).toHaveBeenCalledWith('1', 'digital')
  expect(screen.getByText('Shipping').nextSibling).toHaveTextContent('₹0.00')
  expect(screen.getByText('GST (included)').nextSibling).toHaveTextContent('₹0.00')
  expect(screen.getByText('Total payable').nextSibling).toHaveTextContent('₹199.00')
  expect(screen.queryByLabelText('Pincode')).not.toBeInTheDocument()
})

test('switching to hard copy refetches the quote and shows the address form', async () => {
  renderAt()
  await payButton()
  fireEvent.click(screen.getByLabelText(/hard copy/i))
  expect(await screen.findByLabelText('Pincode')).toBeInTheDocument()
  await waitFor(() => expect(screen.getByText('Total payable').nextSibling).toHaveTextContent('₹399.00'))
  expect(fetchQuote).toHaveBeenLastCalledWith('1', 'hardcopy')
})

test('prefills the stored referral code', async () => {
  getStoredReferral.mockReturnValue('VTP-ABC')
  renderAt()
  expect(await screen.findByLabelText('Referral code (optional)')).toHaveValue('VTP-ABC')
})

test('Pay creates the order without any amount, opens Razorpay, and verify then navigates on success', async () => {
  getStoredReferral.mockReturnValue('VTP-ABC')
  renderAt()
  fireEvent.click(await payButton())
  await waitFor(() => expect(open).toHaveBeenCalled())

  expect(createOrder).toHaveBeenCalledTimes(1)
  const [token, payload] = createOrder.mock.calls[0]
  expect(token).toBe('tok')
  expect(payload).toEqual({ bookId: '1', purchaseType: 'digital', referralCode: 'VTP-ABC' })
  expect(JSON.stringify(payload)).not.toMatch(/amount|price|total/i)

  expect(rzpOptions).toMatchObject({
    key: 'rzp_test_1',
    amount: 19900,
    currency: 'INR',
    order_id: 'order_X',
    name: 'VTEE',
  })

  const resp = { razorpay_order_id: 'order_X', razorpay_payment_id: 'pay_1', razorpay_signature: 'sig' }
  await act(() => rzpOptions.handler(resp))
  expect(verifyPayment).toHaveBeenCalledWith('tok', 77, resp)
  expect(await screen.findByText('Result page')).toBeInTheDocument()
})

test('still navigates to the result page when verify errors (the result page polls)', async () => {
  verifyPayment.mockRejectedValue(new Error('boom'))
  renderAt()
  fireEvent.click(await payButton())
  await waitFor(() => expect(open).toHaveBeenCalled())
  await act(() => rzpOptions.handler({ razorpay_order_id: 'order_X', razorpay_payment_id: 'p', razorpay_signature: 's' }))
  expect(await screen.findByText('Result page')).toBeInTheDocument()
})

test('hard copy sends the address and maps server field errors onto the form', async () => {
  const err = Object.assign(new Error('Validation failed'), { fields: { 'address.pincode': 'Must be 6 digits' } })
  createOrder.mockRejectedValueOnce(err)
  renderAt()
  await payButton()
  fireEvent.click(screen.getByLabelText(/hard copy/i))
  fireEvent.change(await screen.findByLabelText('Full name'), { target: { value: 'Asha' } })
  fireEvent.change(screen.getByLabelText('Pincode'), { target: { value: '12' } })
  fireEvent.click(await payButton())

  expect(await screen.findByText('Must be 6 digits')).toBeInTheDocument()
  expect(createOrder.mock.calls[0][1]).toMatchObject({
    purchaseType: 'hardcopy',
    address: { fullName: 'Asha', pincode: '12' },
  })
  expect(open).not.toHaveBeenCalled()
  expect(screen.getByRole('button', { name: /pay with razorpay/i })).toBeEnabled()
})

test('disables Pay while submitting so a double click creates one order', async () => {
  let release
  createOrder.mockReturnValue(new Promise(r => { release = r }))
  renderAt()
  const btn = await payButton()
  fireEvent.click(btn)
  expect(btn).toBeDisabled()
  fireEvent.click(btn)
  release(created)
  await waitFor(() => expect(open).toHaveBeenCalledTimes(1))
  expect(createOrder).toHaveBeenCalledTimes(1)
})

test('dismissing the modal shows a message and a retry reuses the same order', async () => {
  renderAt()
  fireEvent.click(await payButton())
  await waitFor(() => expect(open).toHaveBeenCalledTimes(1))
  act(() => rzpOptions.modal.ondismiss())
  expect(await screen.findByText(/payment (was )?cancelled/i)).toBeInTheDocument()
  const btn = screen.getByRole('button', { name: /pay with razorpay/i })
  expect(btn).toBeEnabled()

  fireEvent.click(btn)
  await waitFor(() => expect(open).toHaveBeenCalledTimes(2))
  expect(createOrder).toHaveBeenCalledTimes(1)
})

test('payment.failed shows a message and re-enables Pay', async () => {
  renderAt()
  fireEvent.click(await payButton())
  await waitFor(() => expect(open).toHaveBeenCalled())
  act(() => rzpHandlers['payment.failed']({ error: { description: 'Card declined' } }))
  expect(await screen.findByText(/card declined/i)).toBeInTheDocument()
  expect(screen.getByRole('button', { name: /pay with razorpay/i })).toBeEnabled()
})

test('shows an error and allows retry when Razorpay fails to load', async () => {
  loadRazorpay.mockRejectedValueOnce(new Error('Failed to load Razorpay'))
  renderAt()
  fireEvent.click(await payButton())
  expect(await screen.findByText(/failed to load razorpay/i)).toBeInTheDocument()
  expect(screen.getByRole('button', { name: /pay with razorpay/i })).toBeEnabled()
})
