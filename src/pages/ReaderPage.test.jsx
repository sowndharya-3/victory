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
