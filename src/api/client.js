const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080'

export async function apiRequest(path, { method = 'GET', body, token } = {}) {
  const headers = { 'Content-Type': 'application/json' }
  if (token) headers.Authorization = `Bearer ${token}`

  const res = await fetch(`${API_BASE_URL}${path}`, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  })

  const data = await res.json().catch(() => ({}))

  if (!res.ok) {
    // `fields` carries per-field validation messages (e.g. "address.pincode").
    throw Object.assign(new Error(data?.error?.message || 'Request failed'), { fields: data?.error?.fields })
  }

  return data
}

export async function apiRequestFile(path, token, file) {
  const formData = new FormData()
  formData.append('file', file)
  const headers = {}
  if (token) headers.Authorization = `Bearer ${token}`

  const res = await fetch(`${API_BASE_URL}${path}`, { method: 'POST', headers, body: formData })
  const data = await res.json().catch(() => ({}))

  if (!res.ok) {
    throw new Error(data?.error?.message || 'Upload failed')
  }

  return data
}
