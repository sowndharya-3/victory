import { API_BASE_URL, retryAfterSeconds } from './client'

// POST /api/contact -> 200 {ok:true} | 400 {error:'validation_error', fields} | 429 | 502 {error:'email_failed'}.
// Resolves only on 200 {ok:true}; every other outcome (including network errors) throws an error with
// `status` (0 for network), `code`, `fields` and `retryAfter`.
export async function sendContact(payload) {
  let res
  try {
    res = await fetch(`${API_BASE_URL}/api/contact`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
  } catch {
    throw Object.assign(new Error('network'), { status: 0 })
  }
  const data = await res.json().catch(() => ({}))
  if (res.ok && data?.ok === true) return data
  throw Object.assign(new Error('contact failed'), {
    status: res.ok ? 502 : res.status,
    code: typeof data?.error === 'string' ? data.error : data?.error?.code,
    fields: data?.fields,
    retryAfter: retryAfterSeconds(res),
  })
}
