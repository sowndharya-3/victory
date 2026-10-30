import { useState } from 'react'
import { fetchRefunds, approveRefund, rejectRefund, adminErrorMessage, formatRupees } from '../../api/admin'
import { useLoad, LoadState, Table } from './adminUi'

const STATUSES = ['requested', 'approved', 'processing', 'refunded', 'rejected', 'failed']

function RefundRow({ r, onDone }) {
  const [note, setNote] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const canApprove = ['requested', 'approved', 'failed'].includes(r.status)
  const canReject = ['requested', 'approved', 'failed'].includes(r.status)

  const act = async (fn) => {
    setBusy(true)
    setError('')
    try {
      await fn()
      setNote('')
      onDone()
    } catch (err) {
      setError(err.fields?.note || adminErrorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <tr>
      <td>{r.orderNumber}</td>
      <td>{r.purchaseType}</td>
      <td>{r.status}</td>
      <td>{formatRupees(r.amount)}</td>
      <td>{r.reason}</td>
      <td>{r.adminNote ?? '—'}</td>
      <td>{r.createdAt}</td>
      <td>
        {(canApprove || canReject) && (
          <>
            <label htmlFor={`refund-note-${r.id}`}>Note</label>
            <input id={`refund-note-${r.id}`} className="form-control" maxLength={500} value={note} onChange={e => setNote(e.target.value)} />
            {canApprove && <button type="button" disabled={busy} onClick={() => act(() => approveRefund(r.id, note.trim()))}>Approve</button>}
            {canReject && (
              <button type="button" disabled={busy} onClick={() => {
                if (!note.trim()) return setError('A note is required to reject.')
                act(() => rejectRefund(r.id, note.trim()))
              }}>Reject</button>
            )}
          </>
        )}
        {error && <p role="alert" className="form-error">{error}</p>}
      </td>
    </tr>
  )
}

function AdminRefundsPage() {
  const [status, setStatus] = useState('requested')
  const { data, error, loading, reload } = useLoad(() => fetchRefunds({ status }), [status])
  const items = data?.items ?? []

  return (
    <div>
      <h2>Refunds</h2>
      <div className="admin-filters">
        <label htmlFor="refund-status">Status</label>
        <select id="refund-status" value={status} onChange={e => setStatus(e.target.value)}>
          <option value="">All</option>
          {STATUSES.map(s => <option key={s} value={s}>{s}</option>)}
        </select>
      </div>
      <p><small>Approving refunds the full amount through Razorpay, revokes digital access and reverses commission.</small></p>
      <LoadState loading={loading} error={error} empty={!items.length} emptyText="No refunds in this queue." />
      {items.length > 0 && (
        <Table head={['Order', 'Type', 'Status', 'Amount', 'Reason', 'Admin note', 'Requested', 'Action']}>
          {items.map(r => <RefundRow key={`${r.id}-${r.status}`} r={r} onDone={reload} />)}
        </Table>
      )}
    </div>
  )
}

export default AdminRefundsPage
