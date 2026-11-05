import { vi } from 'vitest'
import { sendContact } from './contact'

const reply = (status, body, headers = {}) =>
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: status < 400, status, headers: { get: k => headers[k] ?? null }, json: async () => body }))
afterEach(() => vi.unstubAllGlobals())

test('posts JSON and resolves on ok:true', async () => {
  reply(200, { ok: true })
  await expect(sendContact({ name: 'A' })).resolves.toEqual({ ok: true })
  const [url, init] = fetch.mock.calls[0]
  expect(url).toMatch(/\/api\/contact$/)
  expect(init.method).toBe('POST')
})

test('400 carries fields', async () => {
  reply(400, { error: 'validation_error', fields: { email: 'bad' } })
  await expect(sendContact({})).rejects.toMatchObject({ status: 400, fields: { email: 'bad' } })
})

test('429 carries retryAfter', async () => {
  reply(429, {}, { 'Retry-After': '30' })
  await expect(sendContact({})).rejects.toMatchObject({ status: 429, retryAfter: 30 })
})

test('200 without ok:true and network errors are failures', async () => {
  reply(200, {})
  await expect(sendContact({})).rejects.toMatchObject({ status: 502 })
  vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('x')))
  await expect(sendContact({})).rejects.toMatchObject({ status: 0 })
})
