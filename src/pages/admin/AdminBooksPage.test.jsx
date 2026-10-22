import { render, screen, fireEvent, within, waitFor } from '@testing-library/react'
import { vi } from 'vitest'
import AdminBooksPage from './AdminBooksPage'
import * as booksApi from '../../api/books'

beforeEach(() => {
  localStorage.setItem('vtee-admin-token', 'fake-admin-token')
})

test('loads and displays books from the real API', async () => {
  vi.spyOn(booksApi, 'fetchAdminBooks').mockResolvedValue([
    { id: 1, title: 'Existing Book', published: false, archived: false, hasCover: false, hasIndex: false, hasPdf: false, previewPageCount: 0 },
  ])

  render(<AdminBooksPage />)

  await waitFor(() => {
    expect(screen.getByText('Existing Book')).toBeInTheDocument()
  })
  expect(screen.getByText('Existing Book').closest('tr')).toHaveTextContent('Unpublished')
})

test('adding a book calls createBook and reloads the list', async () => {
  vi.spyOn(booksApi, 'fetchAdminBooks')
    .mockResolvedValueOnce([])
    .mockResolvedValueOnce([{ id: 2, title: 'New Book', published: false, archived: false, hasCover: false, hasIndex: false, hasPdf: false, previewPageCount: 0 }])
  const createSpy = vi.spyOn(booksApi, 'createBook').mockResolvedValue({ id: 2 })

  render(<AdminBooksPage />)
  await waitFor(() => expect(booksApi.fetchAdminBooks).toHaveBeenCalledTimes(1))

  fireEvent.change(screen.getByLabelText(/^title$/i), { target: { value: 'New Book' } })
  fireEvent.change(screen.getByLabelText(/online price/i), { target: { value: '100' } })
  fireEvent.change(screen.getByLabelText(/hard copy price/i), { target: { value: '200' } })
  fireEvent.click(screen.getByRole('button', { name: /add book/i }))

  await waitFor(() => {
    expect(screen.getByText('New Book')).toBeInTheDocument()
  })
  expect(createSpy).toHaveBeenCalledWith('fake-admin-token', { title: 'New Book', onlinePrice: 100, hardcopyPrice: 200 })
})

test('publish button calls publishBook and reloads', async () => {
  vi.spyOn(booksApi, 'fetchAdminBooks')
    .mockResolvedValueOnce([{ id: 3, title: 'Draft Book', published: false, archived: false, hasCover: false, hasIndex: false, hasPdf: false, previewPageCount: 0 }])
    .mockResolvedValueOnce([{ id: 3, title: 'Draft Book', published: true, archived: false, hasCover: false, hasIndex: false, hasPdf: false, previewPageCount: 0 }])
  const publishSpy = vi.spyOn(booksApi, 'publishBook').mockResolvedValue({ published: true })

  render(<AdminBooksPage />)
  await waitFor(() => expect(screen.getByText('Draft Book')).toBeInTheDocument())

  const row = screen.getByText('Draft Book').closest('tr')
  fireEvent.click(within(row).getByRole('button', { name: /^publish$/i }))

  await waitFor(() => {
    expect(row).toHaveTextContent('Published')
  })
  expect(publishSpy).toHaveBeenCalledWith('fake-admin-token', 3)
})
