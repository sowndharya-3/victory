import { useState } from 'react'
import { useSession } from '../../context/SessionContext'
import { loginAdmin } from '../../api/auth'

function AdminLoginPage() {
  const { dispatch } = useSession()
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const handleSubmit = async (e) => {
    e.preventDefault()
    setError('')
    setSubmitting(true)
    try {
      const result = await loginAdmin({ username, password })
      localStorage.setItem('vtee-admin-token', result.token)
      dispatch({ type: 'LOGIN_ADMIN', payload: { username: result.admin.username } })
    } catch (err) {
      setError(err.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="container">
      <h2>Admin Login</h2>
      {error && <p className="form-error">{error}</p>}
      <label htmlFor="admin-username">Username</label>
      <input id="admin-username" className="form-control" value={username} onChange={e => setUsername(e.target.value)} />
      <label htmlFor="admin-password">Password</label>
      <input id="admin-password" type="password" className="form-control" value={password} onChange={e => setPassword(e.target.value)} />
      <button type="submit" className="btn btn-primary" disabled={submitting}>Log In</button>
    </form>
  )
}

export default AdminLoginPage
