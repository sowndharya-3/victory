import { useState } from 'react'
import { useSession } from '../../context/SessionContext'

function AdminBooksPage() {
  const { state, dispatch } = useSession()
  const [title, setTitle] = useState('')
  const [onlinePrice, setOnlinePrice] = useState('')
  const [hardCopyPrice, setHardCopyPrice] = useState('')
  const [editingId, setEditingId] = useState(null)
  const [editTitle, setEditTitle] = useState('')

  const startEdit = (book) => {
    setEditingId(book.id)
    setEditTitle(book.title)
  }

  const handleSaveEdit = (e) => {
    e.preventDefault()
    dispatch({ type: 'UPDATE_BOOK', payload: { id: editingId, changes: { title: editTitle } } })
    setEditingId(null)
  }

  const handleAdd = (e) => {
    e.preventDefault()
    dispatch({
      type: 'ADD_BOOK',
      payload: {
        title,
        description: '',
        type: 'General',
        coverUrl: '/assets/logo.jpeg',
        onlinePrice: Number(onlinePrice),
        hardCopyPrice: Number(hardCopyPrice),
        pageImages: [],
      },
    })
    setTitle('')
    setOnlinePrice('')
    setHardCopyPrice('')
  }

  return (
    <div>
      <h2>Books</h2>
      <form onSubmit={handleAdd}>
        <label htmlFor="new-title">Title</label>
        <input id="new-title" className="form-control" value={title} onChange={e => setTitle(e.target.value)} required />
        <label htmlFor="new-online-price">Online Price</label>
        <input id="new-online-price" type="number" className="form-control" value={onlinePrice} onChange={e => setOnlinePrice(e.target.value)} required />
        <label htmlFor="new-hardcopy-price">Hard Copy Price</label>
        <input id="new-hardcopy-price" type="number" className="form-control" value={hardCopyPrice} onChange={e => setHardCopyPrice(e.target.value)} required />
        <button type="submit" className="btn btn-primary">Add Book</button>
      </form>

      <table>
        <thead>
          <tr><th>Title</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          {state.books.map(book => (
            <tr key={book.id}>
              <td>
                {editingId === book.id ? (
                  <form onSubmit={handleSaveEdit}>
                    <label htmlFor={`edit-title-${book.id}`}>Edit Title</label>
                    <input id={`edit-title-${book.id}`} className="form-control" value={editTitle} onChange={e => setEditTitle(e.target.value)} required />
                    <button type="submit">Save Changes</button>
                    <button type="button" onClick={() => setEditingId(null)}>Cancel</button>
                  </form>
                ) : (
                  book.title
                )}
              </td>
              <td>{book.published ? 'Published' : 'Unpublished'}</td>
              <td>
                <button onClick={() => startEdit(book)}>Edit</button>
                <button onClick={() => dispatch({ type: 'TOGGLE_PUBLISH', payload: { id: book.id } })}>
                  {book.published ? 'Unpublish' : 'Publish'}
                </button>
                <button onClick={() => dispatch({ type: 'DELETE_BOOK', payload: { id: book.id } })}>Delete</button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export default AdminBooksPage
