import { useState } from 'react'
import { fetchAdminOrder, updateFulfilment, openRefund, adminErrorMessage, formatRupees } from '../../api/admin'
import { useLoad, LoadState, Table, label } from './adminUi'
import AdminCodesPanel from './AdminCodesPanel'

// Legal next fulfilment statuses (backend AdminOrderController). Cancelling an unshipped order is a refund instead.
const NEXT = {
  confirmed: ['processing'],
  processing: ['shipped'],
  shipped: ['out_for_delivery', 'delivered', 'cancelled'],
  out_for_delivery: ['delivered', 'cancelled'],
}
const OPEN_REFUND = ['requested', 'approved', 'processing']

function FulfilmentForm({ order, onSaved }) {
  const [status, setStatus] = useState('')
  const [courier, setCourier] = useState(order.courier ?? '')
  const [trackingNumber, setTrackingNumber] = useState(order.trackingNumber ?? '')
  const [reason, setReason] = useState('')
  const [error, setError] = useState('')
  const [fields, setFields] = useState({})
  const [busy, setBusy] = useState(false)
  const options = NEXT[order.fulfilmentStatus] ?? []
  if (!options.length) return <p>No further fulfilment steps.</p>

  const submit = async (e) => {
    e.preventDefault()
    setError('')
    setFields({})
    if (!status) return setError('Choose the next status.')
    if (status === 'shipped' && !courier.trim()) return setFields({ courier: 'Courier is required when shipping.' })
    if (status === 'cancelled' && !reason.trim()) return setFields({ reason: 'Enter a reason for cancelling.' })
    const body = { status }
    if (status === 'shipped') Object.assign(body, { courier: courier.trim(), trackingNumber: trackingNumber.trim() })
    if (status === 'cancelled') body.reason = reason.trim()
    setBusy(true)
    try {
      onSaved(await updateFulfilment(order.id, body))
      setStatus('')
      setReason('')
    } catch (err) {
      setFields(err.fields ?? {})
      setError(adminErrorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} className="admin-form">
      <label htmlFor="next-status">Next status</label>
      <select id="next-status" value={status} onChange={e => setStatus(e.target.value)}>
        <option value="">Choose…</option>
        {options.map(s => <option key={s} value={s}>{label(s)}</option>)}
      </select>
      {status === 'shipped' && (
        <>
          <label htmlFor="courier">Courier</label>
          <input id="courier" className="form-control" maxLength={100} value={courier} onChange={e => setCourier(e.target.value)} />
          {fields.courier && <p className="form-error">{fields.courier}</p>}
          <label htmlFor="tracking-number">Tracking number</label>
          <input id="tracking-number" className="form-control" maxLength={100} value={trackingNumber} onChange={e => setTrackingNumber(e.target.value)} />
          {fields.trackingNumber && <p className="form-error">{fields.trackingNumber}</p>}
        </>
      )}
      {status === 'cancelled' && (
        <>
          <label htmlFor="cancel-reason">Cancel reason</label>
          <input id="cancel-reason" className="form-control" maxLength={255} value={reason} onChange={e => setReason(e.target.value)} />
          <small>Cancelling after shipping moves no money. Open a refund separately if one is due.</small>
          {fields.reason && <p className="form-error">{fields.reason}</p>}
        </>
      )}
      {error && <p role="alert" className="form-error">{error}</p>}
      <button type="submit" className="btn btn-primary" disabled={busy}>Update status</button>
    </form>
  )
}

function RefundForm({ order, onDone }) {
  const [reason, setReason] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const submit = async (e) => {
    e.preventDefault()
    if (!reason.trim()) return setError('Enter a reason for the refund.')
    setBusy(true)
    setError('')
    try {
      await openRefund(order.id, reason.trim())
      setReason('')
      onDone()
    } catch (err) {
      setError(err.fields?.reason || adminErrorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} className="admin-form">
      <label htmlFor="refund-reason">Refund reason</label>
      <input id="refund-reason" className="form-control" maxLength={500} value={reason} onChange={e => setReason(e.target.value)} />
      {error && <p role="alert" className="form-error">{error}</p>}
      <button type="submit" className="btn btn-outline" disabled={busy}>Refund this order</button>
      <small> Refunds the full amount through Razorpay, revokes digital access and reverses commission.</small>
    </form>
  )
}

function AdminOrderDetail({ orderId, onClose, onChanged }) {
  const { data: o, error, loading, reload, setData } = useLoad(() => fetchAdminOrder(orderId), [orderId])
  const changed = (detail) => { if (detail?.id) setData(detail); else reload(); onChanged?.() }

  if (!o) return <section className="admin-detail"><LoadState loading={loading} error={error} /></section>

  const hasOpenRefund = (o.refunds ?? []).some(r => OPEN_REFUND.includes(r.status))
  const a = o.address
  const b = o.breakdown ?? {}

  return (
    <section className="admin-detail" aria-label={`Order ${o.orderNumber}`}>
      <button type="button" onClick={onClose} className="admin-detail-close">Close</button>
      <h3>Order {o.orderNumber}</h3>
      {error && <p role="alert" className="form-error">{error}</p>}
      <p>
        {o.book?.title} · {o.purchaseType === 'hardcopy' ? 'Hard copy' : 'Digital'} · payment <strong>{o.status}</strong>
        {o.purchaseType === 'hardcopy' && <> · fulfilment <strong>{label(o.fulfilmentStatus)}</strong></>}
      </p>
      <p>Customer: {o.customer?.name} · {o.customer?.email} · {o.customer?.phone}</p>
      <p>
        Price {formatRupees(b.subtotal)} · Shipping {formatRupees(b.shipping)} · GST {formatRupees(b.gst)} ({b.gstPercent}% {b.gstMode}) ·
        Total <strong>{formatRupees(b.total)}</strong>
      </p>
      <p>Razorpay payment: {o.razorpayPaymentId ?? '—'} · Paid at: {o.paidAt ?? '—'} · Referral: {o.referralCode ?? '—'}</p>
      {o.commission && (
        <p>Commission: {o.commission.partner?.name} {formatRupees(o.commission.amount)} ({o.commission.status})</p>
      )}

      {o.purchaseType === 'hardcopy' && (
        <>
          <h4>Shipping</h4>
          {a && <p>{a.fullName}, {a.addressLine}, {a.city}, {a.district}, {a.state} {a.pincode} · {a.phone}</p>}
          <p>Tracking code: {o.trackingCode ?? '—'} · Courier: {o.courier ?? '—'} · Tracking number: {o.trackingNumber ?? '—'}</p>
          {o.status === 'paid' && <FulfilmentForm key={o.fulfilmentStatus} order={o} onSaved={changed} />}
        </>
      )}

      {o.purchaseType === 'digital' && o.status === 'paid' && <AdminCodesPanel order={o} onChanged={changed} />}

      <h4>Refunds</h4>
      {o.refunds?.length ? (
        <Table head={['#', 'Status', 'Amount', 'Reason', 'Note', 'Created']}>
          {o.refunds.map(r => (
            <tr key={r.id}><td>{r.id}</td><td>{r.status}</td><td>{formatRupees(r.amount)}</td><td>{r.reason}</td><td>{r.adminNote ?? '—'}</td><td>{r.createdAt}</td></tr>
          ))}
        </Table>
      ) : <p>No refunds.</p>}
      {o.status === 'paid' && !hasOpenRefund && <RefundForm order={o} onDone={() => changed()} />}
      {o.purchaseType === 'hardcopy' && ['confirmed', 'processing'].includes(o.fulfilmentStatus) && (
        <p><small>To cancel an order that has not shipped, refund it: completing the refund cancels fulfilment.</small></p>
      )}

      <h4>Audit log</h4>
      {o.auditLog?.length ? (
        <Table head={['When', 'Action', 'Admin', 'Detail']}>
          {o.auditLog.map(l => (
            <tr key={l.id}><td>{l.createdAt}</td><td>{l.action}</td><td>{l.adminId ?? '—'}</td><td><code>{l.detail ? JSON.stringify(l.detail) : ''}</code></td></tr>
          ))}
        </Table>
      ) : <p>No audit entries.</p>}
    </section>
  )
}

export default AdminOrderDetail
