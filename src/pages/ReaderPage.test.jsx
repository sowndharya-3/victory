import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { vi } from 'vitest'
import { SessionProvider } from '../context/SessionContext'
import ReaderPage from './ReaderPage'
import { fetchAccess, fetchPageBlobUrl } from '../api/reader'
import { fetchBook } from '../api/books'

vi.mock('../api/reader')
vi.mock('../api/books')

function renderPage() {
  return render(
    <SessionProvider>
      <MemoryRouter initialEntries={['/read/7']}>
        <Routes><Route path="/read/:bookId" element={<ReaderPage />} /></Routes>
      </MemoryRouter>
    </SessionProvider>
  )
}

beforeEach(() => {
  URL.revokeObjectURL = vi.fn()
  localStorage.setItem('vtee-auth-token', 'tok')
  fetchBook.mockResolvedValue({ id: 7, title: 'Success Mantra', indexUrl: null })
  fetchPageBlobUrl.mockImplementation((t, b, p) => Promise.resolve(`blob:page-${p}`))
})

afterEach(() => vi.resetAllMocks())

test('without a token asks to log in and calls nothing', () => {
  localStorage.removeItem('vtee-auth-token')
  renderPage()
  expect(screen.getByRole('link', { name: /log in/i })).toHaveAttribute('href', '/account')
  expect(fetchAccess).not.toHaveBeenCalled()
})

test('not owned shows no-access links and never fetches pages', async () => {
  fetchAccess.mockResolvedValue({ owned: false, status: 'none' })
  renderPage()
  expect(await screen.findByText(/you don't have access/i)).toBeInTheDocument()
  expect(screen.getByRole('link', { name: /go to my books/i })).toHaveAttribute('href', '/my-books')
  expect(screen.getByRole('link', { name: /view this book/i })).toHaveAttribute('href', '/books/7')
  expect(fetchPageBlobUrl).not.toHaveBeenCalled()
})

test('owned shows the viewer at page 1', async () => {
  fetchAccess.mockResolvedValue({ owned: true, status: 'active', pageCount: 12 })
  renderPage()
  expect(await screen.findByAltText('Page 1 of 12')).toBeInTheDocument()
  expect(screen.getByText('Page 1 of 12')).toBeInTheDocument()
  expect(screen.getByRole('heading', { name: 'Success Mantra' })).toBeInTheDocument()
  expect(fetchAccess).toHaveBeenCalledWith('tok', '7')
})

test('revoked access shows the no-access message', async () => {
  fetchAccess.mockResolvedValue({ owned: false, status: 'revoked' })
  renderPage()
  expect(await screen.findByText(/access was revoked/i)).toBeInTheDocument()
  expect(fetchPageBlobUrl).not.toHaveBeenCalled()
})

test('owned without a page count is not available yet', async () => {
  fetchAccess.mockResolvedValue({ owned: true, status: 'active' })
  renderPage()
  expect(await screen.findByText(/not available to read yet/i)).toBeInTheDocument()
})

test('access errors: 500 not available, 401 login link, other shows the message', async () => {
  fetchAccess.mockRejectedValueOnce(Object.assign(new Error('render failed'), { status: 500 }))
  const first = renderPage()
  expect(await screen.findByText(/not available to read yet/i)).toBeInTheDocument()
  first.unmount()

  fetchAccess.mockRejectedValueOnce(Object.assign(new Error('unauthorized'), { status: 401 }))
  const second = renderPage()
  expect(await screen.findByRole('link', { name: /log in/i })).toHaveAttribute('href', '/account')
  second.unmount()

  fetchAccess.mockRejectedValueOnce(Object.assign(new Error('Network down'), { status: 0 }))
  renderPage()
  expect(await screen.findByRole('alert')).toHaveTextContent('Network down')
})
