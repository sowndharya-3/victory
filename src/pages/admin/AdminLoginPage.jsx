import { useState } from 'react'
import { useSession } from '../../context/SessionContext'

// Mock credential check only — real authentication arrives with the Phase 2 PHP backend.
const MOCK_USERNAME = 'admin'
const MOCK_PASSWORD = 'admin123'

function AdminLoginPage() {
  const { dispatch } = useSession()
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState(false)

  const handleSubmit = (e) => {
    e.preventDefault()
    if (username === MOCK_USERNAME && password === MOCK_PASSWORD) {
      dispatch({ type: 'LOGIN_ADMIN', payload: { username } })
    } else {
      setError(true)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="container">
      <h2>Admin Login</h2>
      {error && <p className="form-error">Invalid credentials.</p>}
      <label htmlFor="admin-username">Username</label>
      <input id="admin-username" className="form-control" value={username} onChange={e => setUsername(e.target.value)} />
      <label htmlFor="admin-password">Password</label>
      <input id="admin-password" type="password" className="form-control" value={password} onChange={e => setPassword(e.target.value)} />
      <button type="submit" className="btn btn-primary">Log In</button>
    </form>
  )
}

export default AdminLoginPage
