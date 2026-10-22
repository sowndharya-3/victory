import { apiRequest, apiRequestFile } from './client'

export function fetchBooks() {
  return apiRequest('/api/books')
}

export function fetchBook(id) {
  return apiRequest(`/api/books/${id}`)
}

export function fetchAdminBooks(token) {
  return apiRequest('/api/admin/books', { token })
}

export function createBook(token, { title, description, category, onlinePrice, hardcopyPrice }) {
  return apiRequest('/api/admin/books', {
    method: 'POST',
    token,
    body: { title, description, category, onlinePrice, hardcopyPrice },
  })
}

export function updateBook(token, id, changes) {
  return apiRequest(`/api/admin/books/${id}`, { method: 'PUT', token, body: changes })
}

export function publishBook(token, id) {
  return apiRequest(`/api/admin/books/${id}/publish`, { method: 'POST', token })
}

export function unpublishBook(token, id) {
  return apiRequest(`/api/admin/books/${id}/unpublish`, { method: 'POST', token })
}

export function archiveBook(token, id) {
  return apiRequest(`/api/admin/books/${id}/archive`, { method: 'POST', token })
}

export function uploadBookFile(token, id, kind, file) {
  return apiRequestFile(`/api/admin/books/${id}/${kind}`, token, file)
}

export function uploadPreviewPage(token, id, pageNumber, file) {
  return apiRequestFile(`/api/admin/books/${id}/preview-pages/${pageNumber}`, token, file)
}
