import { useState } from 'react'
import { fetchReferrals, approveReferral, rejectReferral, togglePartner, adminErrorMessage } from '../../api/admin'
import { useLoad, LoadState, Table } from './adminUi'

function AdminReferralsPage() {
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [actionError, setActionError] = useState('')
  const [busy, setBusy] = useState(false)
  const { data, error, loading, reload } = useLoad(() => fetchReferrals({ status, page }), [status, page])
  const apps = data?.applications ?? { items: [], total: 0, perPage: 25 }
  const partners = data?.partners ?? []
  const lastPage = Math.max(1, Math.ceil(apps.total / (apps.perPage || 1)))

  const act = async (fn) => {
    setBusy(true)
    setActionError('')
    try {
      await fn()
      await reload()
    } catch (err) {
      setActionError(adminErrorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <h2>Referral Partners</h2>
      {actionError && <p role="alert" className="form-error">{actionError}</p>}
      <LoadState loading={loading} error={error} />

      <h3>Applications</h3>
      <div className="admin-filters">
        <label htmlFor="app-status">Status</label>
        <select id="app-status" value={status} onChange={e => { setStatus(e.target.value); setPage(1) }}>
          <option value="">All</option>
          <option value="pending">Pending</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
        </select>
      </div>
      {!loading && !apps.items.length && <p>No applications.</p>}
      {apps.items.length > 0 && (
        <Table head={['Name', 'Status', 'Phone', 'WhatsApp', 'UPI', 'Gmail', 'Place', 'Application', 'Applied', 'Actions']}>
          {apps.items.map(a => (
            <tr key={a.id}>
              <td>{a.name}</td><td>{a.status}</td><td>{a.phone}</td><td>{a.whatsapp ?? '—'}</td><td>{a.upiId ?? '—'}</td>
              <td>{a.gmail}</td><td>{[a.place, a.district].filter(Boolean).join(', ') || '—'}</td>
              <td>{a.applicationStatus}</td><td>{a.createdAt}</td>
              <td>
                {a.applicationStatus === 'pending' && (
                  <>
                    <button type="button" disabled={busy} onClick={() => act(() => approveReferral(a.id))}>Approve</button>
                    <button type="button" disabled={busy} onClick={() => act(() => rejectReferral(a.id))}>Reject</button>
                  </>
                )}
              </td>
            </tr>
          ))}
        </Table>
      )}
      {apps.total > apps.perPage && (
        <p className="admin-pager">
          <button type="button" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Previous</button>
          <span> Page {page} of {lastPage} </span>
          <button type="button" disabled={page >= lastPage} onClick={() => setPage(p => p + 1)}>Next</button>
        </p>
      )}

      <h3>Partners</h3>
      {!loading && !partners.length && <p>No partners yet.</p>}
      {partners.length > 0 && (
        <Table head={['Name', 'Code', 'Phone', 'Gmail', 'UPI', 'Active', '']}>
          {partners.map(p => (
            <tr key={p.id}>
              <td>{p.name}</td><td>{p.referralCode}</td><td>{p.phone}</td><td>{p.gmail}</td><td>{p.upiId ?? '—'}</td>
              <td>{p.active ? 'Active' : 'Inactive'}</td>
              <td><button type="button" disabled={busy} onClick={() => act(() => togglePartner(p.id))}>{p.active ? 'Deactivate' : 'Activate'}</button></td>
            </tr>
          ))}
        </Table>
      )}
    </div>
  )
}

export default AdminReferralsPage
