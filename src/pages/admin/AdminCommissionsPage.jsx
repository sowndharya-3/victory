import { useEffect, useState } from 'react'
import {
  fetchCommissions, markCommissionPayable, markCommissionPaid, fetchCommissionSettings, saveCommissionSettings,
  adminErrorMessage, formatRupees,
} from '../../api/admin'
import { useLoad, LoadState, Table } from './adminUi'

const STATUSES = ['pending', 'payable', 'paid', 'reversed']

function RatesForm() {
  const [rates, setRates] = useState(null)
  const [fields, setFields] = useState({})
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    fetchCommissionSettings().then(setRates).catch(err => setMessage(adminErrorMessage(err)))
  }, [])

  if (!rates) return message ? <p role="alert" className="form-error">{message}</p> : <p>Loading rates…</p>

  const save = async (e) => {
    e.preventDefault()
    setBusy(true)
    setFields({})
    setMessage('')
    try {
      setRates(await saveCommissionSettings({ digital: rates.digital, hardcopy: rates.hardcopy }))
      setMessage('Rates saved. They apply to orders paid from now on.')
    } catch (err) {
      setFields(err.fields ?? {})
      setMessage(adminErrorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={save} className="admin-form">
      <label htmlFor="digital-rate">Digital commission (₹ per order)</label>
      <input id="digital-rate" inputMode="decimal" className="form-control" value={rates.digital} onChange={e => setRates(r => ({ ...r, digital: e.target.value }))} />
      {fields.digital && <p className="form-error">{fields.digital}</p>}
      <label htmlFor="hardcopy-rate">Hard copy commission (₹ per order)</label>
      <input id="hardcopy-rate" inputMode="decimal" className="form-control" value={rates.hardcopy} onChange={e => setRates(r => ({ ...r, hardcopy: e.target.value }))} />
      {fields.hardcopy && <p className="form-error">{fields.hardcopy}</p>}
      {message && <p aria-live="polite">{message}</p>}
      <button type="submit" className="btn btn-primary" disabled={busy}>Save rates</button>
    </form>
  )
}

function CommissionRow({ c, onDone }) {
  const [reference, setReference] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const act = async (fn) => {
    setBusy(true)
    setError('')
    try {
      await fn()
      onDone()
    } catch (err) {
      setError(err.fields?.payoutReference || adminErrorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <tr>
      <td>{c.orderNumber}</td>
      <td>{c.partner?.name}</td>
      <td>{c.partner?.referralCode}</td>
      <td>{c.purchaseType}</td>
      <td>{formatRupees(c.amount)}</td>
      <td>
        {c.status}
        {c.refundOpen && <span className="admin-badge">Refund open</span>}
        {c.clawbackDue && <span className="admin-badge">Clawback due</span>}
      </td>
      <td>{c.payoutReference ?? '—'}</td>
      <td>{c.createdAt}</td>
      <td>
        {c.status === 'pending' && (
          <button type="button" disabled={busy} onClick={() => act(() => markCommissionPayable(c.id))}>Mark payable</button>
        )}
        {c.status === 'payable' && (
          <>
            <label htmlFor={`payout-${c.id}`}>Payout reference</label>
            <input id={`payout-${c.id}`} className="form-control" maxLength={100} value={reference} onChange={e => setReference(e.target.value)} />
            <button type="button" disabled={busy} onClick={() => {
              if (!reference.trim()) return setError('A payout reference is required.')
              act(() => markCommissionPaid(c.id, reference.trim()))
            }}>Mark paid</button>
          </>
        )}
        {error && <p role="alert" className="form-error">{error}</p>}
      </td>
    </tr>
  )
}

function AdminCommissionsPage() {
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const { data, error, loading, reload } = useLoad(() => fetchCommissions({ status, page }), [status, page])
  const items = data?.items ?? []
  const t = data?.totals
  const lastPage = data ? Math.max(1, Math.ceil(data.total / data.perPage)) : 1

  return (
    <div>
      <h2>Commissions</h2>
      <RatesForm />
      {t && (
        <ul className="admin-cards">
          {STATUSES.map(k => (
            <li key={k}><span>{k[0].toUpperCase() + k.slice(1)} ({t[k]?.count ?? 0})</span><strong>{formatRupees(t[k]?.amount)}</strong></li>
          ))}
          {t.clawbackDue?.count > 0 && <li><span>Clawback due ({t.clawbackDue.count})</span><strong>{formatRupees(t.clawbackDue.amount)}</strong></li>}
        </ul>
      )}
      <div className="admin-filters">
        <label htmlFor="commission-status">Status</label>
        <select id="commission-status" value={status} onChange={e => { setStatus(e.target.value); setPage(1) }}>
          <option value="">All</option>
          {STATUSES.map(s => <option key={s} value={s}>{s}</option>)}
        </select>
      </div>
      <LoadState loading={loading} error={error} empty={!items.length} emptyText="No commissions." />
      {items.length > 0 && (
        <Table head={['Order', 'Partner', 'Code', 'Type', 'Amount', 'Status', 'Payout ref', 'Created', 'Action']}>
          {items.map(c => <CommissionRow key={`${c.id}-${c.status}`} c={c} onDone={reload} />)}
        </Table>
      )}
      {data && data.total > data.perPage && (
        <p className="admin-pager">
          <button type="button" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Previous</button>
          <span> Page {page} of {lastPage} </span>
          <button type="button" disabled={page >= lastPage} onClick={() => setPage(p => p + 1)}>Next</button>
        </p>
      )}
    </div>
  )
}

export default AdminCommissionsPage
