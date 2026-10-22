import { apiRequest } from './client'

export function registerCustomer({ name, email, password, phone }) {
  return apiRequest('/api/auth/register', { method: 'POST', body: { name, email, password, phone } })
}

export function loginCustomer({ email, password }) {
  return apiRequest('/api/auth/login', { method: 'POST', body: { email, password } })
}

export function fetchMe(token) {
  return apiRequest('/api/me', { token })
}

export function loginAdmin({ username, password }) {
  return apiRequest('/api/admin/login', { method: 'POST', body: { username, password } })
}
