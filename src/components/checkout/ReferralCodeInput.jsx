import { useState } from 'react'
import { validateReferralCode } from '../../api/orders'

const MESSAGES = { valid: 'Code applied', invalid: 'Code not recognised' }

// Advisory only: the server re-resolves the code on order creation, so a failed check never blocks payment.
function ReferralCodeInput({ value, onChange }) {
  const [status, setStatus] = useState('')

  const check = async () => {
    const code = value.trim()
    if (!code) return setStatus('')
    try {
      const res = await validateReferralCode(code)
      setStatus(res.valid ? 'valid' : 'invalid')
    } catch {
      setStatus('')
    }
  }

  return (
    <div>
      <label htmlFor="referral-code">Referral code (optional)</label>
      <input
        id="referral-code"
        className="form-control"
        value={value}
        onChange={e => { setStatus(''); onChange(e.target.value) }}
        onBlur={check}
      />
      <p aria-live="polite">{MESSAGES[status] || ''}</p>
    </div>
  )
}

export default ReferralCodeInput
