import { render, screen, fireEvent, within } from '@testing-library/react'
import { SessionProvider } from '../../context/SessionContext'
import AdminBooksPage from './AdminBooksPage'

test('adding a book appends it unpublished, then publish toggles it', () => {
  render(<SessionProvider><AdminBooksPage /></SessionProvider>)

  fireEvent.change(screen.getByLabelText(/title/i), { target: { value: 'New Book' } })
  fireEvent.change(screen.getByLabelText(/online price/i), { target: { value: '100' } })
  fireEvent.change(screen.getByLabelText(/hard copy price/i), { target: { value: '200' } })
  fireEvent.click(screen.getByRole('button', { name: /add book/i }))

  const row = screen.getByText('New Book').closest('tr')
  expect(row).toHaveTextContent(/unpublished/i)

  fireEvent.click(within(row).getByRole('button', { name: /publish/i }))
  expect(row).toHaveTextContent(/published/i)
})

test('editing a book updates its title via UPDATE_BOOK', () => {
  render(<SessionProvider><AdminBooksPage /></SessionProvider>)

  const row = screen.getByText('Success Mantra — Government Job Guide').closest('tr')
  fireEvent.click(within(row).getByRole('button', { name: /^edit$/i }))

  const editTitleInput = screen.getByLabelText(/edit title/i)
  fireEvent.change(editTitleInput, { target: { value: 'Updated Title' } })
  fireEvent.click(screen.getByRole('button', { name: /save changes/i }))

  expect(screen.getByText('Updated Title')).toBeInTheDocument()
})
