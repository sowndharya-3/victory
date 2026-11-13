import { useCallback, useEffect, useRef, useState } from 'react'
import { adminErrorMessage } from '../../api/admin'

// Loads data with loading/error state; `reload()` keeps the old data on screen while it refreshes.
// Only the latest request may update state, so a slow earlier response never overwrites a newer one.
export function useLoad(loader, deps) {
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const latest = useRef(0)

  // eslint-disable-next-line react-hooks/exhaustive-deps
  const reload = useCallback(async () => {
    const id = ++latest.current
    setLoading(true)
    setError('')
    try {
      const result = await loader()
      if (id === latest.current) setData(result)
    } catch (err) {
      if (id === latest.current) setError(adminErrorMessage(err))
    } finally {
      if (id === latest.current) setLoading(false)
    }
  }, deps)

  useEffect(() => { reload() }, [reload])
  return { data, error, loading, reload, setData }
}

export function LoadState({ loading, error, empty, emptyText = 'Nothing here yet.' }) {
  if (loading) return <p>Loading…</p>
  if (error) return <p role="alert" className="form-error">{error}</p>
  if (empty) return <p>{emptyText}</p>
  return null
}

export function Table({ head, children }) {
  return (
    <div className="admin-table-wrap">
      <table>
        <thead><tr>{head.map(h => <th key={h}>{h}</th>)}</tr></thead>
        <tbody>{children}</tbody>
      </table>
    </div>
  )
}

export const label = (s) => (s ? String(s).replace(/_/g, ' ') : '—')
