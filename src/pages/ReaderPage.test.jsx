import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { SessionProvider } from '../context/SessionContext'
import ReaderPage from './ReaderPage'

test('blocks access when book was not purchased', () => {
  render(
    <SessionProvider>
      <MemoryRouter initialEntries={['/read/b1']}>
        <Routes><Route path="/read/:bookId" element={<ReaderPage />} /></Routes>
      </MemoryRouter>
    </SessionProvider>
  )
  expect(screen.getByText(/you don't have access/i)).toBeInTheDocument()
})
