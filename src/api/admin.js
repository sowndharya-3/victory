import { apiRequest } from './client'

// Admin API (backend/README.md endpoint table). Every call sends the `vtee-admin-token`.
// A 401/403 clears the token and fires ADMIN_UNAUTHORIZED; AdminLayout listens and shows the login screen.
// Lists are normalised to { items, page, perPage, total } (GET /api/admin/refunds is a bare array upstream).

export const ADMIN_TOKEN_KEY = 'vtee-admin-token'
export const ADMIN_UNAUTHORIZED = 'vtee-admin-unauthorized'

async function req(path, { method = 'GET', body } = {}) {
  try {
    return await apiRequest(path, { method, body, token: localStorage.getItem(ADMIN_TOKEN_KEY) })
  } catch (err) {
    if (err.status === 401 || err.status === 403) {
      localStorage.removeItem(ADMIN_TOKEN_KEY)
      window.dispatchEvent(new Event(ADMIN_UNAUTHORIZED))
    }
    throw err
  }
}

function qs(params = {}) {
  const p = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '') p.set(k, v)
  const s = p.toString()
  return s ? `?${s}` : ''
}

// Task 19 sends money as numbers, Task 20 as "25.00" strings: one formatter for both.
export function formatRupees(x) {
  if (x === null || x === undefined || x === '' || Number.isNaN(Number(x))) return '—'
  return `₹${Number(x).toFixed(2)}`
}

const CODE_MESSAGES = {
  refund_required: 'This order has not shipped yet, so it cannot be cancelled. Refund it instead.',
  refund_open: 'This order has an open refund. Resolve the refund first.',
  invalid_transition: 'That change is not allowed from the current status. Reload and try again.',
  rate_limited: 'Too many attempts. Wait a few minutes and try again.',
  not_refundable: 'This order cannot be refunded.',
  refund_conflict: 'Another refund is already open for this order.',
  gateway_error: 'The payment gateway did not respond. Try again.',
  not_found: 'Not found. It may have been removed.',
}

export function adminErrorMessage(err) {
  return CODE_MESSAGES[err?.code] || err?.message || 'Something went wrong'
}

export const fetchDashboard = () => req('/api/admin/dashboard')

export const fetchAdminOrders = (params) => req(`/api/admin/orders${qs(params)}`)
export const fetchAdminOrder = (id) => req(`/api/admin/orders/${id}`)
export const updateFulfilment = (id, body) => req(`/api/admin/orders/${id}/fulfilment`, { method: 'PUT', body })
export const openRefund = (orderId, reason) => req(`/api/admin/orders/${orderId}/refund`, { method: 'POST', body: { reason } })

export const verifyCode = (code) => req('/api/admin/codes/verify', { method: 'POST', body: { code } })
export const revokeCode = (orderId, reason) => req(`/api/admin/orders/${orderId}/code/revoke`, { method: 'POST', body: { reason } })
export const reissueCode = (orderId, reason) => req(`/api/admin/orders/${orderId}/code/reissue`, { method: 'POST', body: { reason } })

export const fetchAdminCustomers = (params) => req(`/api/admin/customers${qs(params)}`)
export const fetchAdminCustomer = (id) => req(`/api/admin/customers/${id}`)

export async function fetchRefunds(params) {
  const rows = await req(`/api/admin/refunds${qs(params)}`)
  const items = Array.isArray(rows) ? rows : rows?.items ?? []
  return { items, page: 1, perPage: items.length, total: items.length }
}
export const approveRefund = (id, note) => req(`/api/admin/refunds/${id}/approve`, { method: 'POST', body: note ? { note } : {} })
export const rejectRefund = (id, note) => req(`/api/admin/refunds/${id}/reject`, { method: 'POST', body: { note } })

export async function fetchReferrals(params) {
  const data = await req(`/api/admin/referrals${qs(params)}`)
  const apps = data?.applications
  return {
    applications: Array.isArray(apps) ? { items: apps, page: 1, perPage: apps.length, total: apps.length } : apps ?? { items: [], page: 1, perPage: 0, total: 0 },
    partners: data?.partners ?? [],
  }
}
export const approveReferral = (id) => req(`/api/admin/referrals/${id}/approve`, { method: 'POST' })
export const rejectReferral = (id, reason) => req(`/api/admin/referrals/${id}/reject`, { method: 'POST', body: reason ? { reason } : {} })
export const togglePartner = (id) => req(`/api/admin/partners/${id}/toggle-active`, { method: 'POST' })

export const fetchCommissions = (params) => req(`/api/admin/commissions${qs(params)}`)
export const markCommissionPayable = (id) => req(`/api/admin/commissions/${id}/payable`, { method: 'POST' })
export const markCommissionPaid = (id, payoutReference) => req(`/api/admin/commissions/${id}/paid`, { method: 'POST', body: { payoutReference } })

// Rates and pricing values go to the API as strings (the backend parses "N.NN" without floats).
const asStrings = (obj) => Object.fromEntries(Object.entries(obj).map(([k, v]) => [k, String(v)]))
export const fetchCommissionSettings = () => req('/api/admin/settings/commission')
export const saveCommissionSettings = (rates) => req('/api/admin/settings/commission', { method: 'PUT', body: asStrings(rates) })
export const fetchPricingSettings = () => req('/api/admin/settings/pricing')
export const savePricingSettings = (values) => req('/api/admin/settings/pricing', { method: 'PUT', body: asStrings(values) })

export const fetchAuditLogs = (params) => req(`/api/admin/audit-logs${qs(params)}`)
