import { fetchBooks, fetchBook, createBook, publishBook } from './books'

function mockFetch(responseBody, ok = true, status = 200) {
  global.fetch = vi.fn(() => Promise.resolve({ ok, status, json: () => Promise.resolve(responseBody) }))
}

afterEach(() => {
  vi.restoreAllMocks()
})

test('fetchBooks calls GET /api/books with no auth header', async () => {
  mockFetch([{ id: 1, title: 'A Book' }])
  const result = await fetchBooks()
  expect(result).toEqual([{ id: 1, title: 'A Book' }])
  const [url, options] = global.fetch.mock.calls[0]
  expect(url).toContain('/api/books')
  expect(options.headers.Authorization).toBeUndefined()
})

test('fetchBook calls GET /api/books/:id', async () => {
  mockFetch({ id: 5, title: 'Specific Book' })
  await fetchBook(5)
  const [url] = global.fetch.mock.calls[0]
  expect(url).toContain('/api/books/5')
})

test('createBook sends admin token and POST body', async () => {
  mockFetch({ id: 9 }, true, 201)
  await createBook('admin-token', { title: 'New', onlinePrice: 100, hardcopyPrice: 200 })
  const [url, options] = global.fetch.mock.calls[0]
  expect(url).toContain('/api/admin/books')
  expect(options.method).toBe('POST')
  expect(options.headers.Authorization).toBe('Bearer admin-token')
  expect(JSON.parse(options.body)).toEqual({ title: 'New', onlinePrice: 100, hardcopyPrice: 200 })
})

test('publishBook posts to the publish endpoint', async () => {
  mockFetch({ published: true })
  await publishBook('admin-token', 3)
  const [url, options] = global.fetch.mock.calls[0]
  expect(url).toContain('/api/admin/books/3/publish')
  expect(options.method).toBe('POST')
})
