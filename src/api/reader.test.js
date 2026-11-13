import { fetchPageBlobUrl, fetchAccess } from './reader'

const res = (status, body, headers = {}) => ({
  ok: status < 400, status, json: () => Promise.resolve(body), blob: () => Promise.resolve(new Blob(['x'])),
  headers: { get: (k) => headers[k] ?? null },
})

afterEach(() => vi.restoreAllMocks())

test('page errors expose status, code and Retry-After seconds', async () => {
  global.fetch = vi.fn(() => Promise.resolve(res(503, { error: { code: 'render_busy', message: 'busy' } }, { 'Retry-After': '5' })))
  await expect(fetchPageBlobUrl('t', 7, 2)).rejects.toMatchObject({ status: 503, code: 'render_busy', retryAfter: 5 })
})

test('a missing or junk Retry-After is undefined', async () => {
  global.fetch = vi.fn(() => Promise.resolve(res(503, { error: { code: 'render_busy' } }, { 'Retry-After': 'soon' })))
  await expect(fetchPageBlobUrl('t', 7, 2)).rejects.toMatchObject({ status: 503, retryAfter: undefined })
})

test('access errors carry the same fields', async () => {
  global.fetch = vi.fn(() => Promise.resolve(res(503, { error: { code: 'render_busy', message: 'busy' } }, { 'Retry-After': '5' })))
  await expect(fetchAccess('t', 7)).rejects.toMatchObject({ status: 503, code: 'render_busy', retryAfter: 5 })
})
