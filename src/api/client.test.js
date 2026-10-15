import { apiRequest } from './client'

function mockFetch(responseBody, ok = true, status = 200) {
  global.fetch = vi.fn(() =>
    Promise.resolve({
      ok,
      status,
      json: () => Promise.resolve(responseBody),
    })
  )
}

afterEach(() => {
  vi.restoreAllMocks()
})

test('apiRequest resolves with parsed JSON on success', async () => {
  mockFetch({ token: 'abc123' })
  const result = await apiRequest('/api/auth/login', { method: 'POST', body: { email: 'a@b.com', password: 'x' } })
  expect(result).toEqual({ token: 'abc123' })
})

test('apiRequest throws the backend error message on failure', async () => {
  mockFetch({ error: { code: 'invalid_credentials', message: 'Invalid email or password' } }, false, 401)
  await expect(apiRequest('/api/auth/login', { method: 'POST', body: {} })).rejects.toThrow('Invalid email or password')
})

test('apiRequest sends Authorization header when token is provided', async () => {
  mockFetch({ role: 'customer' })
  await apiRequest('/api/me', { token: 'my-token' })
  const [, options] = global.fetch.mock.calls[0]
  expect(options.headers.Authorization).toBe('Bearer my-token')
})
