import { useEffect, useState } from 'react'
import { fetchPricingSettings, savePricingSettings, adminErrorMessage } from '../../api/admin'

const FIELDS = [
  ['shippingChargeDigital', 'Shipping ₹ (digital)'],
  ['shippingChargeHardcopy', 'Shipping ₹ (hard copy)'],
  ['gstPercentDigital', 'GST % (digital)'],
  ['gstPercentHardcopy', 'GST % (hard copy)'],
]

function AdminSettingsPage() {
  const [values, setValues] = useState(null)
  const [fields, setFields] = useState({})
  const [message, setMessage] = useState('')
  const [loadError, setLoadError] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    fetchPricingSettings().then(setValues).catch(err => setLoadError(adminErrorMessage(err)))
  }, [])

  const save = async (e) => {
    e.preventDefault()
    setBusy(true)
    setFields({})
    setMessage('')
    try {
      const body = Object.fromEntries([...FIELDS.map(([k]) => k), 'gstMode'].map(k => [k, values[k]]))
      setValues(await savePricingSettings(body))
      setMessage('Settings saved. They apply to new orders only.')
    } catch (err) {
      setFields(err.fields ?? {})
      setMessage(adminErrorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <h2>Settings</h2>
      <p className="admin-notice">
        Shipping and GST rules are not yet confirmed by the business (U1/U2). Defaults are ₹0 shipping and 0% GST, inclusive.
        Change these only when the owner confirms the rates.
      </p>
      {loadError && <p role="alert" className="form-error">{loadError}</p>}
      {!values && !loadError && <p>Loading…</p>}
      {values && (
        <form onSubmit={save} className="admin-form">
          {FIELDS.map(([key, text]) => (
            <div key={key}>
              <label htmlFor={key}>{text}</label>
              <input id={key} inputMode="decimal" className="form-control" value={values[key] ?? ''}
                onChange={e => setValues(v => ({ ...v, [key]: e.target.value }))} />
              {fields[key] && <p className="form-error">{fields[key]}</p>}
            </div>
          ))}
          <label htmlFor="gstMode">GST mode</label>
          <select id="gstMode" value={values.gstMode} onChange={e => setValues(v => ({ ...v, gstMode: e.target.value }))}>
            <option value="inclusive">Inclusive (price includes GST)</option>
            <option value="exclusive">Exclusive (GST added on top)</option>
          </select>
          {fields.gstMode && <p className="form-error">{fields.gstMode}</p>}
          {message && <p aria-live="polite">{message}</p>}
          <button type="submit" className="btn btn-primary" disabled={busy}>Save settings</button>
        </form>
      )}
    </div>
  )
}

export default AdminSettingsPage
