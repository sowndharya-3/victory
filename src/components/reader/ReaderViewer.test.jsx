import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { vi, expect } from 'vitest'
import ReaderViewer from './ReaderViewer'
import { fetchPageBlobUrl } from '../../api/reader'

vi.mock('../../api/reader')

const props = { token: 'tok', bookId: '7', pageCount: 12, email: 'buyer@example.com', book: { title: 'Success Mantra', indexUrl: '/uploads/books/7/index.pdf' } }

beforeEach(() => {
  URL.revokeObjectURL = vi.fn()
  fetchPageBlobUrl.mockImplementation((token, bookId, page) => Promise.resolve(`blob:page-${page}`))
})

afterEach(() => vi.resetAllMocks())

const pageImg = (n) => screen.findByAltText(`Page ${n} of 12`)

test('shows page 1 with status and prefetches page 2', async () => {
  render(<ReaderViewer {...props} />)
  expect((await pageImg(1))).toHaveAttribute('src', 'blob:page-1')
  expect(screen.getByText('Page 1 of 12')).toBeInTheDocument()
  expect(fetchPageBlobUrl).toHaveBeenCalledWith('tok', '7', 1, expect.any(AbortSignal))
  expect(fetchPageBlobUrl).toHaveBeenCalledWith('tok', '7', 2, expect.any(AbortSignal))
  expect(screen.getByRole('button', { name: /previous page/i })).toBeDisabled()
})

test('Next button and ArrowRight advance, ArrowLeft goes back', async () => {
  render(<ReaderViewer {...props} />)
  await pageImg(1)
  fireEvent.click(screen.getByRole('button', { name: /next page/i }))
  await pageImg(2)
  fireEvent.keyDown(window, { key: 'ArrowRight' })
  await pageImg(3)
  fireEvent.keyDown(window, { key: 'ArrowLeft' })
  await pageImg(2)
})

test('Next is disabled on the last page; jump input is clamped', async () => {
  render(<ReaderViewer {...props} />)
  await pageImg(1)
  const input = screen.getByLabelText(/go to page/i)
  fireEvent.change(input, { target: { value: '5' } })
  fireEvent.click(screen.getByRole('button', { name: /^go$/i }))
  await pageImg(5)
  expect(fetchPageBlobUrl).toHaveBeenCalledWith('tok', '7', 5, expect.any(AbortSignal))
  fireEvent.change(input, { target: { value: '99' } })
  fireEvent.click(screen.getByRole('button', { name: /^go$/i }))
  await pageImg(12)
  expect(screen.getByRole('button', { name: /next page/i })).toBeDisabled()
  expect(fetchPageBlobUrl).not.toHaveBeenCalledWith('tok', '7', 13, expect.any(AbortSignal))
})

test('swipe left advances, swipe right goes back, vertical swipe ignored', async () => {
  render(<ReaderViewer {...props} />)
  const img = await pageImg(1)
  const swipe = (dx, dy = 0) => {
    fireEvent.touchStart(img, { touches: [{ clientX: 200, clientY: 200 }] })
    fireEvent.touchEnd(img, { changedTouches: [{ clientX: 200 + dx, clientY: 200 + dy }] })
  }
  swipe(-120)
  await pageImg(2)
  swipe(30, 200)
  expect(screen.getByText('Page 2 of 12')).toBeInTheDocument()
  swipe(120)
  await pageImg(1)
})

test('403 shows Access revoked, 429 a slow-down message', async () => {
  fetchPageBlobUrl.mockRejectedValue(Object.assign(new Error('forbidden'), { status: 403 }))
  const { unmount } = render(<ReaderViewer {...props} />)
  expect(await screen.findByText(/access revoked/i)).toBeInTheDocument()
  unmount()
  fetchPageBlobUrl.mockRejectedValue(Object.assign(new Error('rate'), { status: 429 }))
  render(<ReaderViewer {...props} />)
  expect(await screen.findByText(/slow down/i)).toBeInTheDocument()
})

test('watermark, notice and index panel', async () => {
  render(<ReaderViewer {...props} />)
  await pageImg(1)
  expect(screen.getAllByText('Licensed to buyer@example.com · 7').length).toBeGreaterThan(1)
  expect(screen.getByText(/screenshots and screen recording cannot be fully prevented/i)).toBeInTheDocument()
  expect(screen.queryByTitle('Book index')).not.toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: /show index/i }))
  expect(screen.getByTitle('Book index')).toHaveAttribute('src', '/uploads/books/7/index.pdf')
  expect(screen.getByRole('link', { name: /open index/i })).toHaveAttribute('href', '/uploads/books/7/index.pdf')
})

test('blob URLs are revoked when they fall out of the window and on unmount', async () => {
  const { unmount } = render(<ReaderViewer {...props} />)
  await pageImg(1)
  await waitFor(() => expect(fetchPageBlobUrl).toHaveBeenCalledTimes(2))
  fireEvent.change(screen.getByLabelText(/go to page/i), { target: { value: '8' } })
  fireEvent.click(screen.getByRole('button', { name: /^go$/i }))
  await pageImg(8)
  expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:page-1')
  unmount()
  expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:page-8')
})

function deferred() {
  let resolve
  const promise = new Promise(r => { resolve = r })
  return { promise, resolve }
}
const callsFor = (p) => fetchPageBlobUrl.mock.calls.filter(c => c[2] === p).length

test('Next while page 2 prefetch is pending reuses it (one fetch for page 2)', async () => {
  const p2 = deferred()
  fetchPageBlobUrl.mockImplementation((t, b, p) => (p === 2 ? p2.promise : Promise.resolve(`blob:page-${p}`)))
  render(<ReaderViewer {...props} />)
  await pageImg(1)
  await waitFor(() => expect(callsFor(2)).toBe(1))
  fireEvent.click(screen.getByRole('button', { name: /next page/i }))
  p2.resolve('blob:page-2')
  expect(await pageImg(2)).toHaveAttribute('src', 'blob:page-2')
  await waitFor(() => expect(callsFor(3)).toBe(1))
  expect(callsFor(2)).toBe(1)
  expect(URL.revokeObjectURL).not.toHaveBeenCalledWith('blob:page-2')
})

test('rapid Next presses only fetch the final page and its prefetch', async () => {
  render(<ReaderViewer {...props} />)
  await pageImg(1)
  await waitFor(() => expect(callsFor(2)).toBe(1))
  const next = screen.getByRole('button', { name: /next page/i })
  for (let i = 0; i < 5; i++) fireEvent.click(next)
  await pageImg(6)
  await waitFor(() => expect(callsFor(7)).toBe(1))
  expect([3, 4, 5].map(callsFor)).toEqual([0, 0, 0])
  expect(callsFor(6)).toBe(1)
})

test('a late response for a page that left the window is revoked, not shown', async () => {
  const p1 = deferred()
  fetchPageBlobUrl.mockImplementation((t, b, p) => (p === 1 ? p1.promise : Promise.resolve(`blob:page-${p}`)))
  render(<ReaderViewer {...props} />)
  await waitFor(() => expect(callsFor(1)).toBe(1))
  fireEvent.change(screen.getByLabelText(/go to page/i), { target: { value: '8' } })
  fireEvent.click(screen.getByRole('button', { name: /^go$/i }))
  await pageImg(8)
  p1.resolve('blob:page-1')
  await waitFor(() => expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:page-1'))
  expect(screen.queryByAltText('Page 1 of 12')).not.toBeInTheDocument()
})

test('arrow keys are ignored while an input is focused or with modifiers', async () => {
  render(<ReaderViewer {...props} />)
  await pageImg(1)
  const input = screen.getByLabelText(/go to page/i)
  input.focus()
  fireEvent.keyDown(input, { key: 'ArrowRight' })
  fireEvent.keyDown(window, { key: 'ArrowRight', altKey: true })
  expect(screen.getByText('Page 1 of 12')).toBeInTheDocument()
})

test('Retry refetches after a 429 but is not offered for 403', async () => {
  fetchPageBlobUrl.mockRejectedValueOnce(Object.assign(new Error('rate'), { status: 429 }))
  const { unmount } = render(<ReaderViewer {...props} />)
  fireEvent.click(await screen.findByRole('button', { name: /retry/i }))
  expect(await pageImg(1)).toHaveAttribute('src', 'blob:page-1')
  unmount()
  fetchPageBlobUrl.mockRejectedValue(Object.assign(new Error('forbidden'), { status: 403 }))
  render(<ReaderViewer {...props} />)
  await screen.findByText(/access revoked/i)
  expect(screen.queryByRole('button', { name: /retry/i })).not.toBeInTheDocument()
})
