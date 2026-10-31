import { useEffect, useState } from 'react'
import {
  fetchAdminBooks, createBook, updateBook, publishBook, unpublishBook, archiveBook,
  uploadBookFile, uploadPreviewPage,
} from '../../api/books'

function AdminBooksPage() {
  const token = localStorage.getItem('vtee-admin-token')
  const [books, setBooks] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [title, setTitle] = useState('')
  const [onlinePrice, setOnlinePrice] = useState('')
  const [hardCopyPrice, setHardCopyPrice] = useState('')
  const [editingId, setEditingId] = useState(null)
  const [editTitle, setEditTitle] = useState('')
  const [editDescription, setEditDescription] = useState('')

  const load = async () => {
    setLoading(true)
    setError('')
    try {
      setBooks(await fetchAdminBooks(token))
    } catch (err) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [])

  const withBusy = async (fn) => {
    setBusy(true)
    setError('')
    try {
      await fn()
      await load()
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  const handleAdd = (e) => {
    e.preventDefault()
    withBusy(async () => {
      await createBook(token, { title, onlinePrice: Number(onlinePrice), hardcopyPrice: Number(hardCopyPrice) })
      setTitle('')
      setOnlinePrice('')
      setHardCopyPrice('')
    })
  }

  const startEdit = (book) => {
    setEditingId(book.id)
    setEditTitle(book.title)
    setEditDescription(book.description || '')
  }

  const handleSaveEdit = (e) => {
    e.preventDefault()
    withBusy(async () => {
      await updateBook(token, editingId, { title: editTitle, description: editDescription })
      setEditingId(null)
    })
  }

  const handleFileChange = (book, kind) => (e) => {
    const file = e.target.files[0]
    if (!file) return
    withBusy(() => uploadBookFile(token, book.id, kind, file))
  }

  const handlePreviewChange = (book, pageNumber) => (e) => {
    const file = e.target.files[0]
    if (!file) return
    withBusy(() => uploadPreviewPage(token, book.id, pageNumber, file))
  }

  // ponytail: only show the full-page loader on first mount; reloading after a
  // mutation keeps the table mounted so React patches rows in place instead of
  // unmounting/remounting the whole page (which also detached in-flight DOM refs).
  if (loading && books.length === 0) return <div><h2>Books</h2><p>Loading…</p></div>

  return (
    <div>
      <h2>Books</h2>
      {error && <p className="form-error">{error}</p>}
      <form onSubmit={handleAdd}>
        <label htmlFor="new-title">Title</label>
        <input id="new-title" className="form-control" value={title} onChange={e => setTitle(e.target.value)} required />
        <label htmlFor="new-online-price">Online Price</label>
        <input id="new-online-price" type="number" className="form-control" value={onlinePrice} onChange={e => setOnlinePrice(e.target.value)} required />
        <label htmlFor="new-hardcopy-price">Hard Copy Price</label>
        <input id="new-hardcopy-price" type="number" className="form-control" value={hardCopyPrice} onChange={e => setHardCopyPrice(e.target.value)} required />
        <button type="submit" className="btn btn-primary" disabled={busy}>Add Book</button>
      </form>

      <table>
        <thead>
          <tr><th>Title</th><th>Status</th><th>Files</th><th>Actions</th></tr>
        </thead>
        <tbody>
          {books.map(book => (
            <tr key={book.id}>
              <td>
                {editingId === book.id ? (
                  <form onSubmit={handleSaveEdit}>
                    <label htmlFor={`edit-title-${book.id}`}>Edit Title</label>
                    <input id={`edit-title-${book.id}`} className="form-control" value={editTitle} onChange={e => setEditTitle(e.target.value)} required />
                    <label htmlFor={`edit-description-${book.id}`}>Edit Description</label>
                    <textarea id={`edit-description-${book.id}`} className="form-control" rows={4} value={editDescription} onChange={e => setEditDescription(e.target.value)} />
                    <button type="submit" disabled={busy}>Save Changes</button>
                    <button type="button" onClick={() => setEditingId(null)}>Cancel</button>
                  </form>
                ) : (
                  book.title
                )}
              </td>
              <td>{book.archived ? 'Archived' : book.published ? 'Published' : 'Unpublished'}</td>
              <td>
                <label>Cover {book.hasCover ? '✓' : ''}
                  <input type="file" accept="image/*" onChange={handleFileChange(book, 'cover')} disabled={busy} />
                </label>
                <label>Index {book.hasIndex ? '✓' : ''}
                  <input type="file" accept="image/*,application/pdf" onChange={handleFileChange(book, 'index')} disabled={busy} />
                </label>
                <label>PDF {book.hasPdf ? '✓' : ''}
                  <input type="file" accept="application/pdf" onChange={handleFileChange(book, 'pdf')} disabled={busy} />
                </label>
                <label>Preview 1
                  <input type="file" accept="image/*" onChange={handlePreviewChange(book, 1)} disabled={busy} />
                </label>
                <label>Preview 2
                  <input type="file" accept="image/*" onChange={handlePreviewChange(book, 2)} disabled={busy} />
                </label>
              </td>
              <td>
                <button onClick={() => startEdit(book)} disabled={busy}>Edit</button>
                {!book.archived && (
                  <button
                    onClick={() => withBusy(() => (book.published ? unpublishBook(token, book.id) : publishBook(token, book.id)))}
                    disabled={busy}
                  >
                    {book.published ? 'Unpublish' : 'Publish'}
                  </button>
                )}
                {!book.archived && (
                  <button onClick={() => withBusy(() => archiveBook(token, book.id))} disabled={busy}>Archive</button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export default AdminBooksPage
