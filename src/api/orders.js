import { apiRequest } from './client'

// Response shapes (see backend/src/Controllers/*):
//   fetchQuote       -> { bookId, purchaseType, currency, subtotal, shipping, gst, gstPercent, gstMode, total }  (rupees)
//   validateReferralCode -> { valid: false } | { valid: true, partnerName }  (partnerName is masked, e.g. "R***")
//   createOrder      -> { order, razorpay: { keyId, orderId, amount, currency } }  (201; amount in paise)
//   verifyPayment    -> { order }  (200 captured/failed, 202 still authorized; throws on 4xx/5xx)
//   fetchMyOrders    -> [order]  (bare array, newest first)
//   fetchMyOrder     -> order    (bare object; includes `address` for hard copy)
//   fetchLibrary     -> { books: [{ bookId, title, coverUrl, orderId, purchasedAt, accessStatus: 'active'|'revoked' }],
//                         pendingRedemptions: [{ orderId, bookId, title, coverUrl, purchasedAt }] }
//   redeemCode       -> { bookId, orderId }  (throws with the server message on code_invalid / code_already_redeemed /
//                       code_revoked / code_order_not_paid / already_owned / rate_limited)
//   fetchAccessCode  -> { code }  (owner only; 404 if unavailable)   reissueCode -> { code }
//   trackOrder       -> { orderNumber, bookTitle, status, label, updatedAt, courier?, trackingNumber? }  (public; 404 not_found)
// order = { id, orderNumber, bookId, bookTitle, purchaseType, status, fulfilmentStatus, subtotal, shipping, gst,
//           gstPercent, gstMode, total, currency, paidAt, createdAt, trackingCode, digitalCode: null | { status, last4 } }

export function fetchQuote(bookId, type) {
  return apiRequest(`/api/checkout/quote?bookId=${encodeURIComponent(bookId)}&type=${encodeURIComponent(type)}`)
}

export function validateReferralCode(code) {
  return apiRequest(`/api/referral/validate/${encodeURIComponent(code)}`)
}

export function createOrder(token, { bookId, purchaseType, referralCode, address }) {
  return apiRequest('/api/orders', {
    method: 'POST',
    token,
    body: { bookId, purchaseType, referralCode, address },
  })
}

export function verifyPayment(token, orderId, { razorpay_order_id, razorpay_payment_id, razorpay_signature }) {
  return apiRequest(`/api/orders/${orderId}/verify`, {
    method: 'POST',
    token,
    body: { razorpay_order_id, razorpay_payment_id, razorpay_signature },
  })
}

export function fetchMyOrders(token) {
  return apiRequest('/api/me/orders', { token })
}

export function fetchMyOrder(token, orderId) {
  return apiRequest(`/api/me/orders/${orderId}`, { token })
}

export function fetchLibrary(token) {
  return apiRequest('/api/me/library', { token })
}

export function redeemCode(token, code) {
  return apiRequest('/api/me/redeem', { method: 'POST', token, body: { code } })
}

export function fetchAccessCode(token, orderId) {
  return apiRequest(`/api/me/orders/${orderId}/access-code`, { token })
}

export function reissueCode(token, orderId) {
  return apiRequest(`/api/me/orders/${orderId}/reissue-code`, { method: 'POST', token })
}

export function trackOrder(code) {
  return apiRequest(`/api/track/${encodeURIComponent(code)}`)
}

// Public partner sign-up -> 201 { id, applicationStatus: 'pending' }; 400 fields, 409 duplicate_application, 429.
export function submitReferralApplication(form) {
  return apiRequest('/api/referral/applications', { method: 'POST', body: form })
}
