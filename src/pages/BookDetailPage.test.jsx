import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { vi } from 'vitest'
import { SessionProvider } from '../context/SessionContext'
import BookDetailPage from './BookDetailPage'
import * as booksApi from '../api/books'

function renderAt(path) {
  return render(
    <SessionProvider>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/books/:id" element={<BookDetailPage />} />
        </Routes>
      </MemoryRouter>
    </SessionProvider>
  )
}

test('loads the book from the API and renders it', async () => {
  vi.spyOn(booksApi, 'fetchBook').mockResolvedValue({
    id: 6,
    title: 'Real Backend Book',
    description: 'From the API',
    category: 'Fiction',
    coverUrl: '/uploads/books/6/cover.jpg',
    onlinePrice: 150,
    hardcopyPrice: 300,
    indexUrl: null,
    previewPages: [],
  })

  renderAt('/books/6')

  await waitFor(() => {
    expect(screen.getByText('Real Backend Book')).toBeInTheDocument()
  })
  expect(booksApi.fetchBook).toHaveBeenCalledWith('6')
  expect(screen.getByRole('link', { name: /purchase/i })).toBeInTheDocument()
})

test('shows Book not found when fetchBook rejects ', async () => {
  vi.spyOn(booksApi, 'fetchBook').mockRejectedValue(new Error('Book not found'))

  renderAt('/books/999')

  await waitFor(() => {
    expect(screen.getByText(/book not found/i)).toBeInTheDocument()
  })
})
