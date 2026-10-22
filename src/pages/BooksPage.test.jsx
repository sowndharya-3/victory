import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { vi } from 'vitest'
import BooksPage from './BooksPage'
import * as booksApi from '../api/books'

test('lists published books from the real API', async () => {
  vi.spyOn(booksApi, 'fetchBooks').mockResolvedValue([
    { id: 1, title: 'Success Mantra', category: 'Exam Prep', coverUrl: '/x.jpg', onlinePrice: 199, hardcopyPrice: 349 },
  ])

  render(<MemoryRouter><BooksPage /></MemoryRouter>)

  await waitFor(() => {
    expect(screen.getByText(/success mantra/i)).toBeInTheDocument()
  })
})

test('shows an error message if the API call fails', async () => {
  vi.spyOn(booksApi, 'fetchBooks').mockRejectedValue(new Error('Network error'))

  render(<MemoryRouter><BooksPage /></MemoryRouter>)

  await waitFor(() => {
    expect(screen.getByText(/network error/i)).toBeInTheDocument()
  })
})
