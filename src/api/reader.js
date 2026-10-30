import { apiRequest, API_BASE_URL } from './client'

// fetchAccess -> { owned, status: 'active'|'revoked'|'none', pageCount? }
export function fetchAccess(token, bookId) {
  return apiRequest(`/api/books/${encodeURIComponent(bookId)}/access`, { token })
}

// Page images need the Bearer header, so they can't be a plain <img src>; the caller
// owns the returned blob URL and must URL.revokeObjectURL it.
// Errors carry `status` (403 forbidden, 404 out of range, 429 rate_limited).
export async function fetchPageBlobUrl(token, bookId, page, signal) {
  const res = await fetch(`${API_BASE_URL}/api/books/${encodeURIComponent(bookId)}/read/pages/${page}`, {
    headers: { Authorization: `Bearer ${token}` },
    signal,
  })
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw Object.assign(new Error(data?.error?.message || 'Page failed to load'), { status: res.status })
  }
  return URL.createObjectURL(await res.blob())
}
