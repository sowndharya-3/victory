import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { SessionProvider } from '../context/SessionContext'
import BooksPage from './BooksPage'

test('lists only published books', () => {
  render(
    <SessionProvider>
      <MemoryRouter><BooksPage /></MemoryRouter>
    </SessionProvider>
  )
  expect(screen.getByText(/success mantra/i)).toBeInTheDocument()
  expect(screen.queryByText(/scholarship handbook/i)).not.toBeInTheDocument()
})
