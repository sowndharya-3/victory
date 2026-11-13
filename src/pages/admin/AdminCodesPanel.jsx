import { useState } from 'react'
import { verifyCode, revokeCode, reissueCode, adminErrorMessage } from '../../api/admin'

// The reissued plaintext code lives only in `issued` below: shown once, gone on dismiss/unmount.
// Never log it, persist it or put it in a URL.
function ReasonAction({ id, labelText, buttonText, onSubmit }) {
  const [reason, setReason] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const submit = async (e) => {
    e.preventDefault()
    if (!reason.trim()) return setError('Enter a reason.')
    setBusy(true)
    setError('')
    try {
      await onSubmit(reason.trim())
      setReason('')
    } catch (err) {
      setError(err.fields?.reason || adminErrorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} className="admin-form">
      <label htmlFor={id}>{labelText}</label>
      <input id={id} className="form-control" maxLength={255} value={reason} onChange={e => setReason(e.target.value)} />
      {error && <p role="alert" className="form-error">{error}</p>}
      <button type="submit" className="btn btn-outline" disabled={busy}>{buttonText}</button>
    </form>
  )
}

function AdminCodesPanel({ order, onChanged }) {
  const [pasted, setPasted] = useState('')
  const [verified, setVerified] = useState(null)
  const [verifyError, setVerifyError] = useState('')
  const [issued, setIssued] = useState(null)
  const [verifying, setVerifying] = useState(false)

  const verify = async (e) => {
    e.preventDefault()
    const code = pasted.trim()
    setPasted('')
    setVerified(null)
    setVerifyError('')
    if (!code) return
    setVerifying(true)
    try {
      setVerified(await verifyCode(code))
    } catch (err) {
      setVerifyError(err.code === 'not_found' ? 'Unknown code.' : adminErrorMessage(err))
    } finally {
      setVerifying(false)
    }
  }

  const code = order.digitalCode
  return (
    <section className="admin-codes">
      <h4>Access code</h4>
      <p>{code ? <>Status <strong>{code.status}</strong> · ends in ····{code.last4}</> : 'No code issued.'}</p>

      <form onSubmit={verify} className="admin-form">
        <label htmlFor="verify-code">Code to verify</label>
        <input id="verify-code" className="form-control" autoComplete="off" spellCheck={false}
          value={pasted} onChange={e => setPasted(e.target.value)} />
        <button type="submit" className="btn btn-outline" disabled={verifying}>Verify</button>
      </form>
      {verifyError && <p role="alert" className="form-error">{verifyError}</p>}
      {verified && (
        <p>
          {verified.status} · {verified.redeemed ? 'redeemed' : 'not redeemed'} · {verified.bookTitle} · order {verified.orderNumber} ({verified.orderStatus})
        </p>
      )}

      {code && code.status !== 'revoked' && (
        <ReasonAction id="revoke-reason" labelText="Revoke reason" buttonText="Revoke code"
          onSubmit={async (reason) => onChanged(await revokeCode(order.id, reason))} />
      )}
      <ReasonAction id="reissue-reason" labelText="Reissue reason" buttonText="Reissue code"
        onSubmit={async (reason) => {
          const res = await reissueCode(order.id, reason)
          setIssued(res.code)
          onChanged()
        }} />

      {issued && (
        <div className="admin-issued-code" role="status">
          <p>New code: <strong><code>{issued}</code></strong></p>
          <p>Give this to the customer now. It will not be shown again. The previous code no longer works.</p>
          <button type="button" onClick={() => setIssued(null)}>Dismiss</button>
        </div>
      )}
    </section>
  )
}

export default AdminCodesPanel
