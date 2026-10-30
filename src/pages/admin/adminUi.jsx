import { useCallback, useEffect, useState } from 'react'
import { adminErrorMessage } from '../../api/admin'

// Loads data with loading/error state; `reload()` refreshes without blanking the page.
export function useLoad(loader, deps) {
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)

  // eslint-disable-next-line react-hooks/exhaustive-deps
  const reload = useCallback(async () => {
    setError('')
    try {
      setData(await loader())
    } catch (err) {
      setError(adminErrorMessage(err))
    } finally {
      setLoading(false)
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
