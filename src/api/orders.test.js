import { apiRequest } from './client'
import {
  fetchQuote,
  validateReferralCode,
  createOrder,
  verifyPayment,
  fetchMyOrders,
  fetchMyOrder,
} from './orders'

vi.mock('./client', () => ({ apiRequest: vi.fn(() => Promise.resolve({ ok: true })) }))

afterEach(() => {
  vi.clearAllMocks()
})

test('fetchQuote GETs the quote endpoint with encoded bookId and type', async () => {
  await fetchQuote(5, 'digital')
  expect(apiRequest).toHaveBeenCalledWith('/api/checkout/quote?bookId=5&type=digital')
})

test('validateReferralCode URL-encodes the code', async () => {
  await validateReferralCode('VTP A/B')
  expect(apiRequest).toHaveBeenCalledWith('/api/referral/validate/VTP%20A%2FB')
})

test('createOrder POSTs the order body with the customer token', async () => {
  const address = { fullName: 'A', pincode: '600001' }
  await createOrder('tok', { bookId: 3, purchaseType: 'hardcopy', referralCode: 'VTP-1', address })
  expect(apiRequest).toHaveBeenCalledWith('/api/orders', {
    method: 'POST',
    token: 'tok',
    body: { bookId: 3, purchaseType: 'hardcopy', referralCode: 'VTP-1', address },
  })
})

test('verifyPayment POSTs only the three razorpay fields to the order verify endpoint', async () => {
  await verifyPayment('tok', 12, {
    razorpay_order_id: 'o1',
    razorpay_payment_id: 'p1',
    razorpay_signature: 's1',
    extra: 'ignored',
  })
  expect(apiRequest).toHaveBeenCalledWith('/api/orders/12/verify', {
    method: 'POST',
    token: 'tok',
    body: { razorpay_order_id: 'o1', razorpay_payment_id: 'p1', razorpay_signature: 's1' },
  })
})

test('fetchMyOrders GETs /api/me/orders with the token', async () => {
  await fetchMyOrders('tok')
  expect(apiRequest).toHaveBeenCalledWith('/api/me/orders', { token: 'tok' })
})

test('fetchMyOrder GETs /api/me/orders/:id with the token', async () => {
  await fetchMyOrder('tok', 7)
  expect(apiRequest).toHaveBeenCalledWith('/api/me/orders/7', { token: 'tok' })
})
