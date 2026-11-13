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

test('editing a book saves the title and description', async () => {
  vi.spyOn(booksApi, 'fetchAdminBooks').mockResolvedValue([
    { id: 4, title: 'Old Title', description: 'Old text', category: null, onlinePrice: 10, hardcopyPrice: 20, published: true, archived: false, hasCover: false, hasIndex: false, hasPdf: false, previewPageCount: 0 },
  ])
  const updateSpy = vi.spyOn(booksApi, 'updateBook').mockResolvedValue({})

  render(<AdminBooksPage />)
  await waitFor(() => expect(screen.getByText('Old Title')).toBeInTheDocument())
  fireEvent.click(within(screen.getByText('Old Title').closest('tr')).getByRole('button', { name: /^edit$/i }))

  expect(screen.getByLabelText(/edit description/i)).toHaveValue('Old text')
  fireEvent.change(screen.getByLabelText(/edit description/i), { target: { value: 'New text' } })
  fireEvent.click(screen.getByRole('button', { name: /save changes/i }))

  await waitFor(() => expect(updateSpy).toHaveBeenCalledWith('fake-admin-token', 4, { title: 'Old Title', description: 'New text', category: null, onlinePrice: '10', hardcopyPrice: '20' }))
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

const bk = (over = {}) => ({ id: 1, title: 'B', description: '', category: 'Math', onlinePrice: 100, hardcopyPrice: 200, published: false, archived: false, hasCover: false, hasIndex: false, hasPdf: false, previewPageCount: 0, ...over })

test('edit saves category and prices as strings, empty category clears', async () => {
  vi.spyOn(booksApi, 'fetchAdminBooks').mockResolvedValue([bk()])
  const upd = vi.spyOn(booksApi, 'updateBook').mockResolvedValue(bk())
  render(<AdminBooksPage />)
  fireEvent.click(await screen.findByRole('button', { name: 'Edit' }))
  expect(screen.getByLabelText('Category')).toHaveValue('Math')
  fireEvent.change(screen.getByLabelText('Category'), { target: { value: '' } })
  fireEvent.change(screen.getByLabelText('Edit Online Price'), { target: { value: '199.50' } })
  fireEvent.click(screen.getByRole('button', { name: /save changes/i }))
  await waitFor(() => expect(upd).toHaveBeenCalledWith('fake-admin-token', 1, { title: 'B', description: '', category: null, onlinePrice: '199.50', hardcopyPrice: '200' }))
})

test('edit rejects price below 1 client-side and shows server field errors inline', async () => {
  vi.spyOn(booksApi, 'fetchAdminBooks').mockResolvedValue([bk({ published: true })])
  const upd = vi.spyOn(booksApi, 'updateBook').mockRejectedValue(Object.assign(new Error('Validation failed'), { fields: { category: 'At most 100 characters' } }))
  render(<AdminBooksPage />)
  fireEvent.click(await screen.findByRole('button', { name: 'Edit' }))
  fireEvent.change(screen.getByLabelText('Edit Hard Copy Price'), { target: { value: '0.5' } })
  fireEvent.click(screen.getByRole('button', { name: /save changes/i }))
  expect(screen.getByText(/at least 1/i)).toBeInTheDocument()
  expect(upd).not.toHaveBeenCalled()
  fireEvent.change(screen.getByLabelText('Edit Hard Copy Price'), { target: { value: '5' } })
  fireEvent.click(screen.getByRole('button', { name: /save changes/i }))
  expect(await screen.findByText('At most 100 characters')).toBeInTheDocument()
})
