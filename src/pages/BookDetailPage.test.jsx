import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { SessionProvider } from '../context/SessionContext'
import BookDetailPage from './BookDetailPage'

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

test('shows Purchase button and no Read button when not purchased', () => {
  renderAt('/books/b1')
  expect(screen.getByRole('link', { name: /purchase/i })).toBeInTheDocument()
  expect(screen.queryByRole('link', { name: /read book/i })).not.toBeInTheDocument()
})

test('treats an unpublished book as not found', () => {
  renderAt('/books/b3') // b3 is seeded as published: false
  expect(screen.getByText(/book not found/i)).toBeInTheDocument()
  expect(screen.queryByRole('link', { name: /purchase/i })).not.toBeInTheDocument()
})
